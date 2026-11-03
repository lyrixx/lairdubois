<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Model\ReviewableInterface;
use Ladb\CoreBundle\Utils\TypableUtils;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The messages shown after an action (flash bag) are HTML, rendered as is by the layout (|raw):
 * their translations have markup. A value written by a member, a parameter of the translation or
 * a part of the message, is shown as text, neither read as HTML nor escaped twice.
 */
final class FlashMessageTest extends WebTestCase
{
    use XhrTrait;

    // Variables of the messages that hold values of the code, not of a member
    private const VALUES_OF_THE_CODE = [
        'draftPath', // a path of the router
        'invitationCount',
    ];

    public static function provideValues(): iterable
    {
        yield 'markup' => ["<b>L'atelier & co</b>"];
        // Shown the same before and after the escaping
        yield 'apostrophe and ampersand' => ["L'atelier & co"];
    }

    /**
     * An admin on the content of a member.
     *
     * @dataProvider provideValues
     */
    public function testTitleOfAnEventLockedByAnAdmin(string $title): void
    {
        $om = static::getEntityManager();
        $om->find(Event::class, 1)->setTitle($title);
        $om->flush();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $client->request('GET', static::generatePath('core_event_lock', ['id' => 1]));
        $client->followRedirect();

        self::assertShowsAsText($title, $client->getCrawler());
    }

    /**
     * The title as written in the form, by the author.
     *
     * @dataProvider provideValues
     */
    public function testTitleOfACreationUpdatedByItsAuthor(string $title): void
    {
        $client = static::createClientAs(UserFixtures::USER);
        $crawler = $client->request('GET', static::generatePath('core_creation_edit', ['id' => 1]));
        $form = $crawler->filter(\sprintf('form[action="%s"]', static::generatePath('core_creation_update', ['id' => 1])))->form();
        $form['ladb_wonder_creation[title]'] = $title;

        $client->submit($form);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertShowsAsText($title, $client->getCrawler());
    }

    /**
     * A message built by concatenation, without translation.
     *
     * @dataProvider provideValues
     */
    public function testDisplaynameOfATeamLeftByAMember(string $displayname): void
    {
        $team = static::findUser(UserFixtures::TEAM);
        $team->setDisplayname($displayname);
        static::getEntityManager()->flush();

        $client = static::createClientAs(UserFixtures::USER2);
        $client->request('GET', static::generatePath('core_member_delete', ['teamId' => $team->getId()]));
        $client->followRedirect();

        self::assertShowsAsText($displayname, $client->getCrawler());
    }

    /**
     * An unknown username, written in the invitation form of a team: the message names it.
     *
     * @dataProvider provideValues
     */
    public function testUnknownUsernameOfAnInvitation(string $username): void
    {
        $team = static::findUser(UserFixtures::TEAM);

        $client = static::createClientAs(UserFixtures::USER);
        $client->request('GET', static::generatePath('core_member_invitation_create', ['teamId' => $team->getId(), 'recipients' => $username]));
        $client->followRedirect();

        // The first message of each type: the one of the username, before the count of invitations
        $alert = $client->getCrawler()->filter('.alert-error');
        self::assertCount(1, $alert);
        self::assertSame($username.' est invalide.', $alert->text());
        self::assertCount(0, $alert->filter('b'));
    }

    /**
     * Every translated flash message of the code has a French translation, with the parameters
     * that the code gives: without a translation, the page shows the key, and a placeholder
     * without a parameter shows as is (%name%). A key built from a value of the code takes the
     * values of getKeyPartsOfTheCode().
     */
    public function testEveryFlashMessageHasATranslationWithItsParameters(): void
    {
        $catalogue = static::getContainer()->get('translator')->getCatalogue('fr');
        $keyParts = self::getKeyPartsOfTheCode();
        $parser = (new ParserFactory())->createForHostVersion();
        $nodeFinder = new NodeFinder();
        $keyCount = 0;
        $errors = [];
        foreach ((new Finder())->files()->name('*.php')->in(\dirname(__DIR__, 2).'/src') as $file) {
            foreach ($nodeFinder->find($parser->parse($file->getContents()), static fn (Node $node): bool => self::isFlash($node)) as $call) {
                foreach ($nodeFinder->find($call->getArgs()[1]->value, static fn (Node $node): bool => self::isTrans($node)) as $trans) {
                    $location = \sprintf('%s:%d', $file->getRelativePathname(), $trans->getStartLine());
                    $args = $trans->getArgs();
                    $keys = self::findStrings($args[0]->value, $keyParts);
                    $parameters = self::findParameterNames($args[1]->value ?? new Array_());
                    $domain = $args[2]->value ?? new String_('messages');
                    if (null === $keys || null === $parameters || !$domain instanceof String_) {
                        $errors[] = \sprintf('%s %s: unreadable', $location, (new Standard())->prettyPrintExpr($trans));

                        continue;
                    }
                    foreach ($keys as $key) {
                        ++$keyCount;
                        if (!$catalogue->defines($key, $domain->value)) {
                            $errors[] = \sprintf('%s %s: no translation', $location, $key);

                            continue;
                        }
                        preg_match_all('/%\w+%/', $catalogue->get($key, $domain->value), $matches);
                        $placeholders = array_values(array_unique($matches[0]));
                        sort($placeholders);
                        if ($placeholders !== $parameters) {
                            $errors[] = \sprintf('%s %s: takes [%s], given [%s]', $location, $key, implode(', ', $placeholders), implode(', ', $parameters));
                        }
                    }
                }
            }
        }

        // The translated messages of the controllers, found
        self::assertGreaterThan(150, $keyCount);
        self::assertSame([], $errors);
    }

    /**
     * A flash message of a controller takes the keys of its own type (wonder.workshop for
     * Wonder/WorkshopController), never those of another controller, which name the other type
     * (« La création … » for a workshop). The keys of no controller (default.…) are shared.
     */
    public function testEveryFlashMessageOfAControllerTakesTheKeysOfItsType(): void
    {
        $finder = (new Finder())->files()->name('*Controller.php')->in(\dirname(__DIR__, 2).'/src/Ladb/CoreBundle/Controller');
        $types = [];
        foreach ($finder as $file) {
            // Knowledge/School/TestimonialController.php: knowledge.school.testimonial
            $types[$file->getRelativePathname()] = strtolower(preg_replace('/\B[A-Z]/', '_$0', str_replace(['Controller.php', '/'], ['', '.'], $file->getRelativePathname())));
        }
        $keyParts = self::getKeyPartsOfTheCode();
        $parser = (new ParserFactory())->createForHostVersion();
        $nodeFinder = new NodeFinder();
        $ownKeyCount = 0;
        $errors = [];
        foreach ($finder as $file) {
            foreach ($nodeFinder->find($parser->parse($file->getContents()), static fn (Node $node): bool => self::isFlash($node)) as $call) {
                foreach ($nodeFinder->find($call->getArgs()[1]->value, static fn (Node $node): bool => self::isTrans($node)) as $trans) {
                    // An unreadable key fails testEveryFlashMessageHasATranslationWithItsParameters()
                    foreach (self::findStrings($trans->getArgs()[0]->value, $keyParts) ?? [] as $key) {
                        // The longest type: knowledge.school.testimonial, not knowledge.school
                        $keyType = null;
                        foreach ($types as $type) {
                            if (str_starts_with($key, $type.'.') && \strlen($type) > \strlen($keyType ?? '')) {
                                $keyType = $type;
                            }
                        }
                        if ($types[$file->getRelativePathname()] === $keyType) {
                            ++$ownKeyCount;
                        } elseif (null !== $keyType) {
                            $errors[] = \sprintf('%s:%d %s: a key of %s', $file->getRelativePathname(), $trans->getStartLine(), $key, $keyType);
                        }
                    }
                }
            }
        }

        // The keys of their own type, found
        self::assertGreaterThan(100, $ownKeyCount);
        self::assertSame([], $errors);
    }

    /**
     * Every flash message of the code, in the parameters of its translation or in its
     * concatenation: a value that is not a literal goes through htmlspecialchars(), as the "e"
     * filter of Twig, except the plural number of the translator (%count%) and the values of the
     * code (VALUES_OF_THE_CODE). A message written otherwise is listed: escape it, or add it here.
     */
    public function testEveryValueOfAFlashMessageIsEscaped(): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $nodeFinder = new NodeFinder();
        $messageCount = 0;
        $unescaped = [];
        foreach ((new Finder())->files()->name('*.php')->in(\dirname(__DIR__, 2).'/src') as $file) {
            $calls = $nodeFinder->find($parser->parse($file->getContents()), static fn (Node $node): bool => self::isFlash($node));
            foreach ($calls as $call) {
                ++$messageCount;
                foreach (self::findUnescapedValues($call->getArgs()[1]->value) as $value) {
                    $unescaped[] = \sprintf('%s:%d %s', $file->getRelativePathname(), $value->getStartLine(), (new Standard())->prettyPrintExpr($value));
                }
            }
        }

        // The messages of the controllers, found
        self::assertGreaterThan(150, $messageCount);
        self::assertSame([], $unescaped);
    }

    private static function assertShowsAsText(string $value, Crawler $crawler): void
    {
        $alert = $crawler->filter('.alert-success');
        self::assertCount(1, $alert);
        self::assertSame($value, $alert->filter('strong')->last()->text());
        self::assertCount(0, $alert->filter('b'));
    }

    private static function isFlash(Node $node): bool
    {
        if (!$node instanceof MethodCall || !$node->name instanceof Node\Identifier) {
            return false;
        }

        return 'addFlash' === $node->name->toString()
            || ('add' === $node->name->toString() && $node->var instanceof MethodCall && $node->var->name instanceof Node\Identifier && 'getFlashBag' === $node->var->name->toString());
    }

    private static function isTrans(Node $node): bool
    {
        return $node instanceof MethodCall && $node->name instanceof Node\Identifier && 'trans' === $node->name->toString();
    }

    /**
     * The values of the parts of the translation keys that the code builds, by part.
     *
     * @return array<string, list<string>>
     */
    private static function getKeyPartsOfTheCode(): array
    {
        $reviewableTypes = [];
        foreach (static::getEntityManager()->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (is_subclass_of($metadata->getName(), ReviewableInterface::class)) {
                $reviewableTypes[] = TypableUtils::getStrippedNameByType($metadata->getName()::TYPE);
            }
        }

        return [
            // ReviewController::deleteAction()
            '$typableUtils->getStrippedNameByType($entity->getType())' => $reviewableTypes,
        ];
    }

    /**
     * The strings that an expression of literals gives, null for another expression.
     *
     * @param array<string, list<string>> $keyParts
     *
     * @return list<string>|null
     */
    private static function findStrings(Expr $expr, array $keyParts): ?array
    {
        if ($expr instanceof String_) {
            return [$expr->value];
        }
        if ($expr instanceof Concat) {
            $lefts = self::findStrings($expr->left, $keyParts);
            $rights = self::findStrings($expr->right, $keyParts);
            if (null === $lefts || null === $rights) {
                return null;
            }
            $strings = [];
            foreach ($lefts as $left) {
                foreach ($rights as $right) {
                    $strings[] = $left.$right;
                }
            }

            return $strings;
        }
        if ($expr instanceof Ternary && null !== $expr->if) {
            $ifs = self::findStrings($expr->if, $keyParts);
            $elses = self::findStrings($expr->else, $keyParts);

            return null === $ifs || null === $elses ? null : [...$ifs, ...$elses];
        }

        return $keyParts[(new Standard())->prettyPrintExpr($expr)] ?? null;
    }

    /**
     * The sorted names of the parameters of a translation, null when they are not literals.
     *
     * @return list<string>|null
     */
    private static function findParameterNames(Expr $parameters): ?array
    {
        if (!$parameters instanceof Array_) {
            return null;
        }
        $names = [];
        foreach ($parameters->items as $parameter) {
            if (!$parameter->key instanceof String_) {
                return null;
            }
            $names[] = $parameter->key->value;
        }
        sort($names);

        return $names;
    }

    /**
     * @return iterable<Expr>
     */
    private static function findUnescapedValues(Expr $message): iterable
    {
        if ($message instanceof Concat) {
            yield from self::findUnescapedValues($message->left);
            yield from self::findUnescapedValues($message->right);
        } elseif (self::isTrans($message)) {
            $parameters = $message->getArgs()[1]->value ?? new Array_();
            if (!$parameters instanceof Array_) {
                yield $parameters;

                return;
            }
            foreach ($parameters->items as $parameter) {
                if (!($parameter->key instanceof String_ && '%count%' === $parameter->key->value) && !self::isEscaped($parameter->value)) {
                    yield $parameter->value;
                }
            }
        } elseif ($message instanceof MethodCall && $message->name instanceof Node\Identifier && 'renderView' === $message->name->toString()) {
            // A template, escaped by Twig
        } elseif (!$message instanceof String_ && !self::isEscaped($message) && !($message instanceof Variable && \in_array($message->name, self::VALUES_OF_THE_CODE, true))) {
            yield $message;
        }
    }

    private static function isEscaped(Expr $value): bool
    {
        return $value instanceof FuncCall
            && $value->name instanceof Node\Name
            && 'htmlspecialchars' === $value->name->toString()
            &&'ENT_QUOTES | ENT_SUBSTITUTE' === (new Standard())->prettyPrintExpr($value->getArgs()[1]->value ?? new String_(''));
    }
}

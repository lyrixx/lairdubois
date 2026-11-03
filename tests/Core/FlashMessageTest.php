<?php

namespace Tests\Core;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Event\Event;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
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

    /**
     * @return iterable<Expr>
     */
    private static function findUnescapedValues(Expr $message): iterable
    {
        if ($message instanceof Concat) {
            yield from self::findUnescapedValues($message->left);
            yield from self::findUnescapedValues($message->right);
        } elseif ($message instanceof MethodCall && $message->name instanceof Node\Identifier && 'trans' === $message->name->toString()) {
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

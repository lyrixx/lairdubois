<?php

namespace Tests\Knowledge;

use Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Tests\XhrTrait;

/**
 * A knowledge is filled with values that users propose and vote for, field by field: the best
 * voted value of a field gives its data to the knowledge. These actions are XHR, as on the site.
 */
trait ValueTrait
{
    use XhrTrait;

    /**
     * Proposes a value for $field of $knowledge, from the form of the page of the field, with
     * personal knowledge as source. The response is the one of the proposal.
     *
     * @param array<string, string> $values by full field name: "ladb_knowledge_value_text[data]"...
     */
    protected static function proposeValue(Client $client, AbstractKnowledge $knowledge, string $field, array $values): void
    {
        $crawler = $client->request('GET', static::generatePath('core_knowledge_field_show', self::getFieldParameters($knowledge, $field)));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = self::selectValueForm($crawler, static::generatePath('core_knowledge_value_create', self::getFieldParameters($knowledge, $field)));
        $form->setValues($values);
        $form[self::getSourceTypeFieldName($form)] = (string) BaseValue::SOURCE_TYPE_PERSONAL;

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }

    /**
     * The form of the value $value of $field, as its author or an admin edits it.
     */
    protected static function getValueEditForm(Client $client, AbstractKnowledge $knowledge, string $field, BaseValue $value): Form
    {
        $parameters = self::getFieldParameters($knowledge, $field) + ['id' => $value->getId()];
        $crawler = static::xhr($client, 'GET', static::generatePath('core_knowledge_value_edit', $parameters));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return self::selectValueForm($crawler, static::generatePath('core_knowledge_value_update', $parameters));
    }

    /**
     * Votes for $value, from the form of a new vote: a down vote needs a reason.
     *
     * @param 'up'|'down' $way
     */
    protected static function vote(Client $client, BaseValue $value, string $way, string $reason = ''): void
    {
        $parameters = ['entityType' => $value->getType(), 'entityId' => $value->getId(), 'way' => $way];
        $crawler = static::xhr($client, 'GET', static::generatePath('core_vote_new', $parameters));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter(\sprintf('form[action^="%s"]', static::generatePath('core_vote_create', $parameters)))->form();
        $form['ladb_core_new_vote[body]'] = $reason;

        static::xhr($client, 'POST', $form->getUri(), $form->getPhpValues());
    }

    /**
     * The last proposed value, of any knowledge.
     */
    protected static function findLastValue(): BaseValue
    {
        return static::getEntityManager()->getRepository(BaseValue::class)->findOneBy([], ['id' => 'DESC']);
    }

    /**
     * @return array{entityType: int, entityId: int, field: string}
     */
    private static function getFieldParameters(AbstractKnowledge $knowledge, string $field): array
    {
        return ['entityType' => $knowledge->getType(), 'entityId' => $knowledge->getId(), 'field' => $field];
    }

    private static function selectValueForm(Crawler $crawler, string $action): Form
    {
        return $crawler->filter(\sprintf('form[action="%s"]', $action))->form();
    }

    private static function getSourceTypeFieldName(Form $form): string
    {
        foreach (array_keys($form->all()) as $name) {
            if (str_ends_with($name, '[sourceType]')) {
                return $name;
            }
        }

        throw new \LogicException('The form of the value has no source type.');
    }
}

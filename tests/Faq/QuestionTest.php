<?php

namespace Tests\Faq;

use Ladb\CoreBundle\Entity\Faq\Question;
use Tests\EditorialTestCase;

final class QuestionTest extends EditorialTestCase
{
    protected static function getRoutePrefix(): string
    {
        return 'core_faq_question';
    }

    protected static function getEntityClass(): string
    {
        return Question::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_faq_qquestion[title]' => 'comment changer mon avatar ?',
            'ladb_faq_qquestion[icon]' => 'user',
            'ladb_faq_qquestion[bodyBlocks][0][body]' => 'Depuis vos **paramètres**, onglet « Profil ».',
            'ladb_faq_qquestion[tags]' => 'aide,profil',
        ];
    }

    /**
     * The page of a question is also at its slug.
     */
    public function testShowBySlug(): void
    {
        $question = $this->find(static::ID);

        $client = static::createClient();
        $crawler = $client->request('GET', $this->path('show', ['id' => $question->getSlug()]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsStringIgnoringCase($question->getTitle(), $crawler->filter('title')->text(null, false));
    }
}

<?php

namespace Tests\Blog;

use Ladb\CoreBundle\Entity\Blog\Post;
use Tests\EditorialTestCase;

final class PostTest extends EditorialTestCase
{
    protected const NOTIFIES_THE_FOLLOWERS = true;

    protected static function getRoutePrefix(): string
    {
        return 'core_blog_post';
    }

    protected static function getEntityClass(): string
    {
        return Post::class;
    }

    protected function getNewFormValues(): array
    {
        return [
            'ladb_blog_post[title]' => 'les nouveautés de l\'automne',
            'ladb_blog_post[mainPicture]' => static::createPicture('test-blog-post'),
            'ladb_blog_post[bodyBlocks][0][body]' => 'Les plans ont maintenant leur propre page de **téléchargement**.',
            'ladb_blog_post[tags]' => 'nouveautés',
        ];
    }
}

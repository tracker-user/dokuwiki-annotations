<?php

namespace dokuwiki\plugin\annotations\test;

use DokuWikiTest;

/**
 * Storage, CRUD, permission and orphan-detection tests for the annotations
 * helper. The helper is pure logic, so most of this needs no HTTP request.
 *
 * @group plugin_annotations
 * @group plugins
 */
class HelperTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['annotations'];

    /**
     * @return \helper_plugin_annotations
     */
    protected function helper()
    {
        return new \helper_plugin_annotations();
    }

    // -----------------------------------------------------------------
    //  Permission rules (pure functions)
    // -----------------------------------------------------------------

    public function testCanAnnotateRequiresLoginAndRead(): void
    {
        $h = $this->helper();
        $this->assertFalse($h->canAnnotate('', AUTH_READ), 'anonymous may not annotate');
        $this->assertFalse($h->canAnnotate('alice', AUTH_NONE), 'no read access → no annotate');
        $this->assertTrue($h->canAnnotate('alice', AUTH_READ), 'logged in + read → annotate');
        $this->assertTrue($h->canAnnotate('alice', AUTH_EDIT), 'edit access implies read');
    }

    public function testCanEditAnnotationAuthorOrAdmin(): void
    {
        $h = $this->helper();
        $ann = ['author' => 'alice'];
        $this->assertTrue($h->canEditAnnotation($ann, 'alice', false), 'author may edit');
        $this->assertFalse($h->canEditAnnotation($ann, 'bob', false), 'non-author may not edit');
        $this->assertTrue($h->canEditAnnotation($ann, 'bob', true), 'admin may edit anyone');
        $this->assertFalse($h->canEditAnnotation($ann, '', true), 'anonymous never edits');
    }

    public function testCanEditReplyAuthorOrAdmin(): void
    {
        $h = $this->helper();
        $reply = ['author' => 'alice'];
        $this->assertTrue($h->canEditReply($reply, 'alice', false));
        $this->assertFalse($h->canEditReply($reply, 'bob', false));
        $this->assertTrue($h->canEditReply($reply, 'bob', true));
    }

    public function testCanClearAdminOnly(): void
    {
        $h = $this->helper();
        $this->assertTrue($h->canClear(true));
        $this->assertFalse($h->canClear(false));
    }

    // -----------------------------------------------------------------
    //  Annotation CRUD
    // -----------------------------------------------------------------

    public function testCreateGetAndStats(): void
    {
        $h  = $this->helper();
        $id = 'anntest:crud';

        $ann = $h->createAnnotation($id, ['exact' => 'hello world'], 'alice', 'A comment');
        $this->assertIsArray($ann);
        $this->assertNotEmpty($ann['id']);
        $this->assertEquals('open', $ann['status']);
        $this->assertEquals('alice', $ann['author']);

        $this->assertCount(1, $h->getAnnotations($id));
        $this->assertEquals($ann['id'], $h->getAnnotation($id, $ann['id'])['id']);
        $this->assertEquals(['total' => 1, 'open' => 1, 'resolved' => 0], $h->getStats($id));
    }

    public function testCreateRejectsEmptyBodyAnchorOrAuthor(): void
    {
        $h  = $this->helper();
        $id = 'anntest:reject';

        $this->assertFalse($h->createAnnotation($id, ['exact' => 'x'], 'alice', '   '), 'empty body');
        $this->assertFalse($h->createAnnotation($id, ['exact' => ''], 'alice', 'body'), 'empty exact');
        $this->assertFalse($h->createAnnotation($id, ['exact' => 'x'], '', 'body'), 'empty author');
        $this->assertSame([], $h->getAnnotations($id), 'nothing was stored');
    }

    public function testBodyAndQuoteAreLengthCapped(): void
    {
        $h  = $this->helper();
        $id = 'anntest:caps';

        $ann = $h->createAnnotation($id, ['exact' => str_repeat('q', 2000)], 'alice', str_repeat('x', 20000));
        $this->assertIsArray($ann);
        $this->assertEquals(10000, mb_strlen($ann['body']), 'body capped at MAX_BODY');
        $this->assertEquals(1000, mb_strlen($ann['anchor']['exact']), 'quote capped at MAX_QUOTE');
    }

    public function testWhitespaceNormalisedInAnchor(): void
    {
        $h  = $this->helper();
        $id = 'anntest:ws';
        $ann = $h->createAnnotation($id, ['exact' => "  foo\n\t  bar  "], 'alice', 'b');
        $this->assertEquals('foo bar', $ann['anchor']['exact']);
    }

    public function testUpdateAndDeleteAnnotation(): void
    {
        $h  = $this->helper();
        $id = 'anntest:upd';
        $ann = $h->createAnnotation($id, ['exact' => 'foo'], 'alice', 'first');

        $this->assertTrue($h->updateAnnotationBody($id, $ann['id'], 'second'));
        $this->assertEquals('second', $h->getAnnotation($id, $ann['id'])['body']);
        $this->assertFalse($h->updateAnnotationBody($id, 'nope', 'x'), 'missing id → false');
        $this->assertFalse($h->updateAnnotationBody($id, $ann['id'], '   '), 'empty body → false');

        $this->assertTrue($h->deleteAnnotation($id, $ann['id']));
        $this->assertNull($h->getAnnotation($id, $ann['id']));
        $this->assertFalse($h->deleteAnnotation($id, $ann['id']), 'already gone → false');
    }

    public function testStatusFlow(): void
    {
        $h  = $this->helper();
        $id = 'anntest:status';
        $ann = $h->createAnnotation($id, ['exact' => 'foo'], 'alice', 'b');

        $this->assertTrue($h->setStatus($id, $ann['id'], 'resolved', 'bob'));
        $resolved = $h->getAnnotation($id, $ann['id']);
        $this->assertEquals('resolved', $resolved['status']);
        $this->assertEquals('bob', $resolved['resolved_by']);
        $this->assertGreaterThan(0, $resolved['resolved_at']);

        $this->assertTrue($h->setStatus($id, $ann['id'], 'open', 'bob'));
        $reopened = $h->getAnnotation($id, $ann['id']);
        $this->assertEquals('open', $reopened['status']);
        $this->assertEquals('', $reopened['resolved_by']);

        $this->assertFalse($h->setStatus($id, $ann['id'], 'bogus', 'bob'), 'invalid status → false');
    }

    // -----------------------------------------------------------------
    //  Reply CRUD
    // -----------------------------------------------------------------

    public function testReplyCrud(): void
    {
        $h  = $this->helper();
        $id = 'anntest:reply';
        $ann = $h->createAnnotation($id, ['exact' => 'foo'], 'alice', 'b');

        $reply = $h->addReply($id, $ann['id'], 'bob', 'a reply');
        $this->assertIsArray($reply);
        $this->assertNotEmpty($reply['id']);
        $this->assertCount(1, $h->getAnnotation($id, $ann['id'])['replies']);

        $this->assertTrue($h->updateReply($id, $ann['id'], $reply['id'], 'edited reply'));
        $this->assertEquals('edited reply', $h->getAnnotation($id, $ann['id'])['replies'][0]['body']);

        $this->assertTrue($h->deleteReply($id, $ann['id'], $reply['id']));
        $this->assertCount(0, $h->getAnnotation($id, $ann['id'])['replies']);

        $this->assertFalse($h->addReply($id, 'missing-ann', 'bob', 'x'), 'reply to missing annotation → false');
    }

    // -----------------------------------------------------------------
    //  Bulk maintenance
    // -----------------------------------------------------------------

    public function testClearResolved(): void
    {
        $h  = $this->helper();
        $id = 'anntest:clearres';
        $keep = $h->createAnnotation($id, ['exact' => 'one'], 'alice', 'b1');
        $drop = $h->createAnnotation($id, ['exact' => 'two'], 'alice', 'b2');
        $h->setStatus($id, $drop['id'], 'resolved', 'alice');

        $this->assertEquals(1, $h->clearResolved($id));
        $remaining = $h->getAnnotations($id);
        $this->assertCount(1, $remaining);
        $this->assertEquals($keep['id'], $remaining[0]['id']);
    }

    // -----------------------------------------------------------------
    //  Orphan detection against a rendered page
    // -----------------------------------------------------------------

    public function testFindAndClearOrphanedAgainstRenderedPage(): void
    {
        $id = 'anntest:orphan';
        saveWikiText($id, 'Hello world, this is a wiki page about cats.', 'setup');

        $h = $this->helper();
        $present = $h->createAnnotation($id, ['exact' => 'wiki page about cats'], 'alice', 'present');
        $gone    = $h->createAnnotation($id, ['exact' => 'text that is not here'], 'alice', 'gone');

        $orphanIds = array_map(static function ($a) {
            return $a['id'];
        }, $h->findOrphaned($id));

        $this->assertContains($gone['id'], $orphanIds, 'a missing quote is orphaned');
        $this->assertNotContains($present['id'], $orphanIds, 'a present quote is not orphaned');

        $this->assertEquals(1, $h->clearOrphaned($id), 'only the orphan is cleared');
        $remaining = $h->getAnnotations($id);
        $this->assertCount(1, $remaining);
        $this->assertEquals($present['id'], $remaining[0]['id']);
    }
}

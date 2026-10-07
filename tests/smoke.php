<?php
require __DIR__ . '/bootstrap.php';
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Repository\NewsItemRepository;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Support\Time;

T::group('harness smoke');
$db = new FakeWpDb();
$t  = new TableNames($db->prefix());
$repo = new NewsItemRepository($db, $t);

$i = new NewsItem();
$i->sourceId=1; $i->guid='g1'; $i->canonicalUrl='https://a.test/x'; $i->contentHash=str_repeat('a',64);
$i->title='Hello'; $i->createdAt=Time::now(); $i->status=NewsItem::STATUS_NORMALIZED;
$id = $repo->insert($i);
T::ok($id>0, 'insert returns id');
T::eq(1, $repo->countAll(), 'countAll works');
T::ok($repo->exists('g1','https://a.test/x','',1), 'exists() detects dup');
T::eq(0, $repo->countDuplicates(), 'countDuplicates is 0 (the B-2 bug)');
exit(T::summary());

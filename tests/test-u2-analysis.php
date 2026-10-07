<?php
/**
 * A-3 security intel · A-4 claim risk · A-5 image plan · A-6 audit
 * A-7 filler detection · A-12 source tiers · A-13 categories · A-16 hooks
 *
 * Everything here is deterministic by design, so the tests assert exact
 * verdicts rather than ranges.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\Audit\ArticleAuditor;
use NewsDesk\AI\Application\Content\FillerPhraseDetector;
use NewsDesk\AI\Application\FactCheck\ClaimRiskAssessor;
use NewsDesk\AI\Application\Images\ImagePlanService;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Security\SecurityIntelExtractor;
use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\NewsCategory;

/* ═══════════════════════ A-7 — filler detection ═══════════════════════ */

T::group( 'A-7 — the banned phrases are actually detected, not just prompted' );

$d = new FillerPhraseDetector();
$r = $d->scan( 'در دنیای امروز وردپرس گامی مهم در مسیر بهبود برداشته است.' );
T::eq( 2, $r['total'], 'both cliches are found' );
T::ok( $r['penalty'] > 0, 'a penalty is applied' );
T::ok( in_array( 'در دنیای امروز', $r['phrases'], true ), 'the opener is named' );

T::group( 'A-7 — clean Persian prose scores zero' );

$r = $d->scan( 'وردپرس ۶.۹ منتشر شد. این نسخه ویرایشگر بلوک را بازنویسی می‌کند و سرعت بارگذاری را ۱۲ درصد بهبود می‌دهد.' );
T::eq( 0, $r['total'], 'no false positives on real reporting' );
T::eq( 0.0, $r['penalty'], 'and no penalty' );
T::ok( ! $r['critical'], 'not critical' );

T::group( 'A-7 — cosmetic differences cannot hide a phrase' );

// Arabic yeh/kaf and ZWNJ are the usual ways this list gets bypassed.
T::ok( $d->scan( 'در دنياي امروز' )['total'] >= 1, 'Arabic yeh spelling still matches' );
T::ok( $d->scan( '<p>شایان ذکر است</p>' )['total'] >= 1, 'HTML tags do not hide it' );
T::ok( $d->scan( 'شایان    ذکر    است' )['total'] >= 1, 'extra whitespace does not hide it' );

T::group( 'A-7 — heavy filler is critical and the penalty is capped' );

$spam = str_repeat( 'بدون شک شایان ذکر است که در دنیای امروز انقلابی در کار است. ', 6 );
$r    = $d->scan( $spam );
T::ok( $r['critical'], 'many hits is a critical failure' );
T::ok( $r['penalty'] <= FillerPhraseDetector::MAX_PENALTY, 'the penalty is capped' );

T::group( 'A-7 — the list is filterable and structured content is scanned' );

$r = $d->scanArticle(
	array(
		'title'    => 'یک تیتر معمولی',
		'sections' => array( array( 'heading' => 'بخش', 'body' => 'بدون شک این مهم است' ) ),
		'faq'      => array( array( 'q' => 'چرا؟', 'a' => 'شایان ذکر است که مهم است' ) ),
	)
);
T::eq( 2, $r['total'], 'sections and FAQ are both scanned' );
T::ok( count( $d->phrases() ) >= 25, 'the shipped list is substantial' );

/* ═══════════════════════ A-3 — security intel ═════════════════════════ */

T::group( 'A-3 — CVE, CVSS and severity are extracted from real advisory text' );

$x   = new SecurityIntelExtractor();
$out = $x->extract( 'A flaw tracked as CVE-2026-12345 has a CVSS score of 9.8. Fixed in 6.4.2. Affects versions prior to 6.4.2.', 'WordPress security release' );
T::ok( $out['is_security'], 'recognised as security news' );
T::eq( array( 'CVE-2026-12345' ), $out['cve_ids'], 'the CVE id is captured' );
T::eq( 9.8, $out['cvss_score'], 'the CVSS score is captured' );
T::eq( 'CRITICAL', $out['severity'], '9.8 is CRITICAL' );
T::ok( in_array( '6.4.2', $out['fixed_versions'], true ), 'the fixed version is captured' );
T::eq( 'high', $out['confidence'], 'a hard identifier means high confidence' );

T::group( 'A-3 — severity bands follow CVSS v3' );

foreach ( array( 9.0 => 'CRITICAL', 7.5 => 'HIGH', 5.0 => 'MEDIUM', 2.0 => 'LOW' ) as $score => $band ) {
	T::eq( $band, $x->extract( "CVSS $score" )['severity'], "CVSS $score is $band" );
}

T::group( 'A-3 — active exploitation is flagged' );

T::ok( $x->extract( 'The bug is being actively exploited in the wild.' )['exploited'], 'exploitation detected' );
T::ok( ! $x->extract( 'A patch is available for the reported issue.' )['exploited'], 'no false positive' );

T::group( 'A-3 — nothing is invented when the text says nothing' );

$out = $x->extract( 'WordPress 6.9 adds a new block to the editor.' );
T::ok( ! $out['is_security'], 'ordinary news is not security news' );
T::eq( array(), $out['cve_ids'], 'no CVE is fabricated' );
T::eq( null, $out['cvss_score'], 'no score is fabricated' );
T::eq( 'NONE', $out['severity'], 'no severity is fabricated' );
T::eq( 'none', $out['confidence'], 'confidence says so' );

T::group( 'A-3 — a security story with no identifier is low confidence, not high' );

$out = $x->extract( 'A security update was released today.' );
T::ok( $out['is_security'], 'still recognised as security' );
T::eq( 'low', $out['confidence'], 'but flagged as low confidence' );

T::group( 'A-3 — multiple CVEs are all captured, without duplicates' );

$out = $x->extract( 'CVE-2026-1111 and CVE-2026-2222 and again CVE-2026-1111.' );
T::eq( 2, count( $out['cve_ids'] ), 'two distinct ids' );

/* ═══════════════════════ A-4 — claim risk ═════════════════════════════ */

T::group( 'A-4 — risk reflects consequence, not just wording' );

$a = new ClaimRiskAssessor();

function claim( string $text, string $type = EvidenceClaim::TYPE_FACT, string $status = EvidenceClaim::STATUS_UNVERIFIED, float $conf = 0.8 ): EvidenceClaim {
	$c                     = new EvidenceClaim();
	$c->claimText          = $text;
	$c->claimType          = $type;
	$c->verificationStatus = $status;
	$c->confidence         = $conf;
	return $c;
}

T::eq( 'CRITICAL', $a->assess( claim( 'The flaw is actively exploited' ) )['risk'], 'exploitation is CRITICAL' );
T::eq( 'HIGH', $a->assess( claim( 'The company faces a lawsuit' ) )['risk'], 'legal claims are HIGH' );
T::eq( 'HIGH', $a->assess( claim( 'Usage grew 40%', EvidenceClaim::TYPE_STAT ) )['risk'], 'statistics are HIGH' );
T::eq( 'MEDIUM', $a->assess( claim( 'Released on Tuesday', EvidenceClaim::TYPE_DATE ) )['risk'], 'dates are MEDIUM' );
T::eq( 'LOW', $a->assess( claim( 'The editor has a new sidebar' ) )['risk'], 'ordinary facts are LOW' );

T::group( 'A-4 — verified claims are kept whatever the risk' );

$v = $a->assess( claim( 'actively exploited', EvidenceClaim::TYPE_FACT, EvidenceClaim::STATUS_VERIFIED ) );
T::eq( 'KEEP', $v['action'], 'verified is kept' );

T::group( 'A-4 — contradicted claims are removed, never softened' );

foreach ( array( EvidenceClaim::STATUS_CONTRADICTED, EvidenceClaim::STATUS_REJECTED ) as $st ) {
	T::eq( 'REMOVE', $a->assess( claim( 'anything', EvidenceClaim::TYPE_FACT, $st ) )['action'], "$st is removed" );
}

T::group( 'A-4 — an unverified CRITICAL claim never ships as fact' );

$v = $a->assess( claim( 'The flaw is actively exploited', EvidenceClaim::TYPE_FACT, EvidenceClaim::STATUS_UNVERIFIED ) );
T::eq( 'REMOVE', $v['action'], 'unverified + critical = REMOVE' );
$v = $a->assess( claim( 'The flaw is actively exploited', EvidenceClaim::TYPE_FACT, EvidenceClaim::STATUS_PARTIALLY_VERIFIED ) );
T::eq( 'ATTRIBUTE', $v['action'], 'partially verified + critical = ATTRIBUTE' );

T::group( 'A-4 — low confidence triggers more research, not publication' );

T::eq( 'RESEARCH_MORE', $a->assess( claim( 'Revenue doubled', EvidenceClaim::TYPE_FACT, EvidenceClaim::STATUS_UNVERIFIED, 0.2 ) )['action'], 'HIGH risk + low confidence' );
T::eq( 'MARK_UNCERTAIN', $a->assess( claim( 'Shipped in March', EvidenceClaim::TYPE_DATE, EvidenceClaim::STATUS_UNVERIFIED, 0.2 ) )['action'], 'MEDIUM risk + low confidence' );

T::group( 'A-4 — every action returned is one the spec defines' );

$seen = array();
foreach ( array( 'exploited', 'lawsuit', 'sidebar', 'Released Tuesday' ) as $t ) {
	foreach ( array( EvidenceClaim::STATUS_UNVERIFIED, EvidenceClaim::STATUS_VERIFIED, EvidenceClaim::STATUS_PARTIALLY_VERIFIED, EvidenceClaim::STATUS_CONTRADICTED ) as $st ) {
		$seen[] = $a->assess( claim( $t, EvidenceClaim::TYPE_FACT, $st ) )['action'];
	}
}
foreach ( array_unique( $seen ) as $act ) {
	T::ok( in_array( $act, EvidenceClaim::ACTIONS, true ), "'$act' is a documented action" );
}

T::group( 'A-4 — apply() writes the verdict onto the claim' );

$c = $a->apply( claim( 'The flaw is actively exploited' ) );
T::eq( 'CRITICAL', $c->risk, 'risk stored on the entity' );
T::eq( 'REMOVE', $c->action, 'action stored on the entity' );
T::contains( 'risk', implode( ',', array_keys( $c->toDbRow() ) ), 'and it round-trips to the DB row' );

/* ═══════════════════════ A-5 — image plan ═════════════════════════════ */

T::group( 'A-5 — the image phase produces a plan, per the spec' );

$p    = new ImagePlanService();
$plan = $p->plan( make_story( array( 'title' => 'WordPress 6.9 ships new block editor' ) ) );

T::eq( 'planned', $plan['status'], 'status is planned' );
T::eq( ImagePlanService::CONCEPTS, count( $plan['concepts'] ), 'three concepts are offered' );
T::ok( '' !== $plan['alt_text'], 'alt text is suggested' );
T::ok( count( $plan['search_terms'] ) > 0, 'search terms are suggested' );
T::ok( count( $plan['avoid'] ) >= 4, 'constraints are listed' );
T::eq( '16:9', $plan['aspect'], 'a news aspect ratio is recommended' );

T::group( 'A-5 — the plan needs no network, no AI and no cost' );

$src = file_get_contents( NEWSDESK_DIR . 'src/Application/Images/ImagePlanService.php' );
foreach ( array( 'AiGateway', 'generateImage', 'HttpClient', 'wp_remote' ) as $needle ) {
	T::ok( false === strpos( $src, $needle ), "plan service does not use $needle" );
}

T::group( 'A-5 — real image generation is now OFF by default' );

$defaults = NewsroomSettings::defaults();
T::eq( false, $defaults['image_generate_enabled'], 'generation is opt-in, per the plan-only spec' );
$s = new NewsroomSettings( $defaults );
T::ok( ! $s->imageGenerateEnabled(), 'and the accessor agrees' );

T::group( 'A-5 — an empty story still yields a usable plan' );

$plan = $p->plan( make_story( array( 'title' => '' ) ) );
T::eq( 'planned', $plan['status'], 'still planned' );
T::ok( '' !== $plan['alt_text'], 'alt text is never empty' );

/* ═══════════════════════ A-6 — the audit ══════════════════════════════ */

T::group( 'A-6 — fifteen axes, weights summing to 100' );

T::eq( 15, count( ArticleAuditor::AXES ), 'exactly fifteen axes' );
$sum = 0;
foreach ( ArticleAuditor::AXES as $a2 ) {
	$sum += $a2[0];
}
T::eq( 100, $sum, 'the weights sum to 100' );

function good_article(): array {
	return array(
		'title'         => 'وردپرس ۶.۹ با ویرایشگر بلوک تازه منتشر شد',
		'focus_keyword' => 'وردپرس ۶.۹',
		'sections'      => array(
			array( 'heading' => 'چه چیزی تغییر کرد', 'paragraphs' => array( str_repeat( 'این نسخه ویرایشگر بلوک را بازنویسی می‌کند و سرعت را بهبود می‌دهد. ', 12 ) ), 'claim_ids' => array( 'c1' ) ),
			array( 'heading' => 'چرا مهم است', 'paragraphs' => array( str_repeat( 'برای مدیران سایت این یعنی زمان بارگذاری کمتر و نگهداری ساده‌تر. ', 12 ) ), 'claim_ids' => array( 'c2' ) ),
		),
		'faq'           => array(
			array( 'q' => 'کی منتشر شد؟', 'a' => 'امروز.' ),
			array( 'q' => 'رایگان است؟', 'a' => 'بله.' ),
			array( 'q' => 'چطور به‌روزرسانی کنم؟', 'a' => 'از پیشخوان.' ),
		),
	);
}

$auditor = new ArticleAuditor();
$ctx     = array( 'allowed_claim_ids' => array( 'c1', 'c2' ), 'sources' => array( 'https://wordpress.org/news' ), 'language' => 'fa_IR' );
$res     = $auditor->audit( good_article(), make_story(), $ctx );

T::ok( $res['passed'], 'a sound article passes' );
T::eq( array(), $res['critical'], 'with no critical failures' );
T::ok( $res['score'] >= ArticleAuditor::PASS_MARK, 'and clears the pass mark' );

T::group( 'A-6 — a fabricated claim reference is a critical failure' );

$bad                        = good_article();
$bad['sections'][0]['claim_ids'] = array( 'c1', 'MADE-UP-999' );
$res                        = $auditor->audit( $bad, make_story(), $ctx );
T::ok( ! $res['passed'], 'the article fails' );
T::ok( in_array( 'claims_grounded', $res['critical'], true ), 'the grounding axis is the reason' );

T::group( 'A-6 — a critical failure blocks even a high-scoring piece' );

$bad = good_article();
$res = $auditor->audit( $bad, make_story(), array_merge( $ctx, array( 'sources' => array() ) ) );
T::ok( ! $res['passed'], 'no sources = blocked' );
T::ok( in_array( 'sources_present', $res['critical'], true ), 'and it is named' );
T::ok( $res['score'] > ArticleAuditor::PASS_MARK, 'even though the score is still above the pass mark' );

T::group( 'A-6 — leftover placeholders never reach a reader' );

$bad                                = good_article();
$bad['sections'][0]['paragraphs'][] = 'TODO: write the rest of this section';
$res                                = $auditor->audit( $bad, make_story(), $ctx );
T::ok( in_array( 'no_placeholders', $res['critical'], true ), 'TODO is caught' );

$bad                                = good_article();
$bad['sections'][0]['paragraphs'][] = 'See https://example.com/source for details';
$res                                = $auditor->audit( $bad, make_story(), $ctx );
T::ok( in_array( 'no_fabricated_refs', $res['critical'], true ), 'example.com URLs are caught' );

T::group( 'A-6 — an unattributed quotation is flagged' );

$bad                                = good_article();
$bad['sections'][0]['paragraphs'][] = '«این بزرگ‌ترین تغییر در تاریخ وردپرس بوده و همه چیز را دگرگون می‌کند»';
$res                                = $auditor->audit( $bad, make_story(), $ctx );
T::eq( 0.0, $res['axes']['quotes_attributed']['score'], 'the quote axis fails' );

$ok                                = good_article();
$ok['sections'][0]['paragraphs'][] = 'مت مالنوگ گفت: «این بزرگ‌ترین تغییر در تاریخ وردپرس بوده و همه چیز را دگرگون می‌کند»';
$res                               = $auditor->audit( $ok, make_story(), $ctx );
T::ok( $res['axes']['quotes_attributed']['score'] > 0, 'an attributed quote passes' );

T::group( 'A-6 — a Persian article written in English is caught' );

$bad = good_article();
$bad['sections'] = array(
	array( 'heading' => 'What changed', 'paragraphs' => array( str_repeat( 'This release rewrites the block editor and improves performance. ', 12 ) ), 'claim_ids' => array( 'c1' ) ),
);
$res = $auditor->audit( $bad, make_story(), $ctx );
T::eq( 0.0, $res['axes']['language_consistent']['score'], 'the language axis fails' );

T::group( 'A-6 — a security story without a CVE is flagged' );

$res = $auditor->audit( good_article(), make_story(), array_merge( $ctx, array( 'security' => array( 'is_security' => true, 'cve_ids' => array(), 'fixed_versions' => array() ) ) ) );
T::eq( 0.0, $res['axes']['security_complete']['score'], 'incomplete security fields are flagged' );

$res = $auditor->audit( good_article(), make_story(), array_merge( $ctx, array( 'security' => array( 'is_security' => true, 'cve_ids' => array( 'CVE-2026-1' ) ) ) ) );
T::ok( $res['axes']['security_complete']['score'] > 0, 'a CVE satisfies it' );

T::group( 'A-6 — every axis reports why, not just a number' );

$res = $auditor->audit( good_article(), make_story(), $ctx );
$missing = array();
foreach ( $res['axes'] as $key => $axis ) {
	if ( '' === trim( (string) $axis['note'] ) ) {
		$missing[] = $key;
	}
}
T::eq( array(), $missing, 'all fifteen axes carry an explanation' );

/* ═══════════════ A-12 / A-13 — taxonomies ═════════════════════════════ */

T::group( 'A-12 — source types and tiers exist with sane trust defaults' );

T::eq( 5, count( Source::SOURCE_TYPES ), 'five source types' );
foreach ( array( 'PRIMARY', 'TECHNICAL', 'MEDIA', 'COMMUNITY', 'SECURITY' ) as $t ) {
	T::ok( in_array( $t, Source::SOURCE_TYPES, true ), "$t is a source type" );
}
T::eq( array( 1, 2, 3, 4 ), Source::TIERS, 'four tiers' );
T::ok( Source::TIER_TRUST[1] > Source::TIER_TRUST[4], 'tier 1 outranks tier 4' );

T::group( 'A-12 — the fields round-trip through the database row' );

$s              = new Source();
$s->sourceType  = Source::SOURCE_SECURITY;
$s->tier        = 1;
$row            = $s->toDbRow();
T::eq( 'SECURITY', $row['source_type'], 'source_type is persisted' );
T::eq( 1, $row['tier'], 'tier is persisted' );

$back = Source::fromDbRow( array( 'source_type' => 'PRIMARY', 'tier' => 2 ) );
T::eq( 'PRIMARY', $back->sourceType, 'and reads back' );
T::eq( 2, $back->tier, 'with the tier' );

T::group( 'A-12 — invalid values fall back instead of corrupting the row' );

$bad = Source::fromDbRow( array( 'source_type' => 'NONSENSE', 'tier' => 99 ) );
T::eq( 'MEDIA', $bad->sourceType, 'unknown type falls back to MEDIA' );
T::eq( 3, $bad->tier, 'out-of-range tier falls back to 3' );

T::group( 'A-13 — fifteen news categories, classified deterministically' );

T::eq( 15, count( NewsCategory::ALL ), 'fifteen categories' );
T::eq( 15, count( NewsCategory::labels() ), 'each has a Persian label' );

$cases = array(
	'Critical vulnerability CVE-2026-1 patched' => 'SECURITY',
	'WordPress 6.9 now available'               => 'CORE_RELEASE',
	'Gutenberg 20 adds new block controls'      => 'GUTENBERG',
	'WooCommerce checkout gets faster'          => 'ECOMMERCE',
	'How to speed up your site'                 => 'PERFORMANCE',
	'Automattic announces acquisition'          => 'BUSINESS',
	'WordCamp Europe 2026 announced'            => 'COMMUNITY',
	'A quiet day in the garden'                 => 'OTHER',
);
foreach ( $cases as $title => $expected ) {
	T::eq( $expected, NewsCategory::classify( $title ), "'$title' → $expected" );
}

T::group( 'A-13 — security outranks everything on a mixed headline' );

// A security flaw in a plugin is security news first.
T::eq( 'SECURITY', NewsCategory::classify( 'Popular plugin patches critical vulnerability' ), 'security wins the tie' );
T::ok( NewsCategory::isValid( 'SECURITY' ), 'isValid accepts a real category' );
T::ok( ! NewsCategory::isValid( 'MADE_UP' ), 'and rejects an invented one' );

/* ═══════════════════════ A-16 — hooks ═════════════════════════════════ */

T::group( 'A-16 — the spec hooks exist in the code' );

$scorer = file_get_contents( NEWSDESK_DIR . 'src/Application/Scoring/StoryScorer.php' );
T::contains( "'newsdesk_news_score'", $scorer, 'newsdesk_news_score filter is fired' );
$editorial = file_get_contents( NEWSDESK_DIR . 'src/Application/StoryEditorialService.php' );
T::contains( "'newsdesk_news_selected'", $editorial, 'newsdesk_news_selected action is fired' );

T::group( 'A-16 — a filtered score cannot break selection' );

// The clamp matters: a filter returning 5000 must not let a story bypass gates.
T::contains( 'min( 100.0', $scorer, 'the filtered score is clamped to 0-100' );

exit( T::summary() );

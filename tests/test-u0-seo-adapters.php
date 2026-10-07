<?php
/**
 * U-0 regression tests: B-6 — SEO adapters existed but were never registered
 * or called, while DraftService wrote Yoast/Rank Math meta unconditionally.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\Seo\AioseoAdapter;
use NewsDesk\AI\Application\Seo\NativeSeoAdapter;
use NewsDesk\AI\Application\Seo\RankMathAdapter;
use NewsDesk\AI\Application\Seo\SeoAdapterInterface;
use NewsDesk\AI\Application\Seo\SeoAdapterResolver;
use NewsDesk\AI\Application\Seo\YoastAdapter;

/** An adapter that always runs and records what it received. */
final class SpyAdapter implements SeoAdapterInterface {
	public $applied = array();
	private $id;
	private $active;
	private $throw;

	public function __construct( string $id, bool $active = true, bool $throw = false ) {
		$this->id     = $id;
		$this->active = $active;
		$this->throw  = $throw;
	}
	public function id(): string {
		return $this->id;
	}
	public function isActive(): bool {
		return $this->active;
	}
	public function apply( int $postId, array $seo ): void {
		if ( $this->throw ) {
			throw new \RuntimeException( 'boom' );
		}
		$this->applied[] = array( $postId, $seo );
	}
}

function seo_package(): array {
	return array(
		'title'              => 'عنوان سئو',
		'meta_description'   => 'توضیح متا برای موتور جست‌وجو.',
		'slug'               => 'seo-slug',
		'canonical'          => 'https://site.test/canonical',
		'focus_keyword'      => 'وردپرس',
		'secondary_keywords' => array( 'امنیت', 'افزونه' ),
		'jsonld'             => array( '@type' => 'NewsArticle' ),
		'focus_entities'     => array( 'WordPress' ),
	);
}

/* ══════════════════════════════════════════ B-6 ══════════════════════════ */

T::group( 'B-6 — all four adapters are registered in the container' );

$plugin = file_get_contents( NEWSDESK_DIR . 'src/Core/Plugin.php' );
foreach ( array( 'NativeSeoAdapter', 'YoastAdapter', 'RankMathAdapter', 'AioseoAdapter' ) as $cls ) {
	T::contains( $cls, $plugin, "$cls is referenced in Plugin.php" );
}
T::contains( 'SeoAdapterResolver', $plugin, 'the resolver is registered' );

T::group( 'B-6 — DraftService no longer hardcodes vendor meta keys' );

$draftSrc = file_get_contents( NEWSDESK_DIR . 'src/Application/Content/DraftService.php' );
T::ok( false === strpos( $draftSrc, "'_yoast_wpseo_title'    =>" ), 'no unconditional _yoast_wpseo_title write' );
T::ok( false === strpos( $draftSrc, "'rank_math_title'       =>" ), 'no unconditional rank_math_title write' );
T::contains( 'applySeoAdapters', $draftSrc, 'the adapter path is invoked instead' );

T::group( 'B-6 — a draft on a site with no SEO plugin gets no vendor meta' );

$index  = new FakePostIndex();
$writer = new FakePostWriter();
// No SEO plugin constants are defined in the harness, so Yoast/RankMath/AIOSEO
// must all be inactive.
$resolver = new SeoAdapterResolver(
	array( new NativeSeoAdapter(), new YoastAdapter(), new RankMathAdapter(), new AioseoAdapter() ),
	new FakeLogger()
);
T::eq( 1, count( $resolver->active() ), 'only the native adapter is active' );
T::eq( 'native', $resolver->active()[0]->id(), 'and it is the native one' );

$svc = new NewsDesk\AI\Application\Content\DraftService(
	$writer,
	$index,
	new FakeLinkRepo(),
	new NewsDesk\AI\Application\NewsroomSettings( NewsDesk\AI\Application\NewsroomSettings::defaults() ),
	new FakeLogger(),
	$resolver
);

WpTestState::reset();
$out  = $svc->create(
	make_story(),
	array(
		'title'         => 'تیتر',
		'lead'          => 'لید',
		'focus_keyword' => 'وردپرس',
		'sections'      => array( array( 'heading' => 'بخش', 'paragraphs' => array( 'متن.' ) ) ),
	),
	seo_package(),
	array(),
	array(),
	array(),
	42
);
$meta = $out['meta'];
T::ok( ! isset( $meta['_yoast_wpseo_title'] ), 'no Yoast title meta on a site without Yoast' );
T::ok( ! isset( $meta['_yoast_wpseo_metadesc'] ), 'no Yoast description meta' );
T::ok( ! isset( $meta['rank_math_title'] ), 'no Rank Math title meta' );
T::ok( ! isset( $meta['rank_math_focus_keyword'] ), 'no Rank Math keyword meta' );
T::ok( isset( $meta['nd_focus_keyword'] ), 'our own nd_* meta is still written' );

$postId = $out['post_id'];
T::ok( $postId > 0, 'draft was created' );
T::eq(
	'عنوان سئو',
	get_post_meta( $postId, NativeSeoAdapter::META_PREFIX . 'title', true ),
	'the native adapter DID persist the package (apply() is finally called)'
);
T::eq(
	'https://site.test/canonical',
	get_post_meta( $postId, NativeSeoAdapter::META_PREFIX . 'canonical', true ),
	'canonical is stored natively'
);

T::group( 'B-6 — the resolver routes to every active adapter' );

$spyA     = new SpyAdapter( 'a' );
$spyB     = new SpyAdapter( 'b' );
$inactive = new SpyAdapter( 'c', false );
$r        = new SeoAdapterResolver( array( $spyA, $spyB, $inactive ), new FakeLogger() );
$ran      = $r->apply( 7, seo_package() );

T::eq( array( 'a', 'b' ), $ran, 'both active adapters ran, the inactive one did not' );
T::eq( 1, count( $spyA->applied ), 'adapter a received exactly one call' );
T::eq( 7, $spyA->applied[0][0], 'with the right post id' );
T::eq( 'عنوان سئو', $spyA->applied[0][1]['title'], 'and the full package' );
T::eq( 0, count( $inactive->applied ), 'inactive adapter was never called' );

T::group( 'B-6 — a broken adapter cannot break draft creation' );

$logger  = new FakeLogger();
$boom    = new SpyAdapter( 'boom', true, true );
$healthy = new SpyAdapter( 'healthy' );
$r2      = new SeoAdapterResolver( array( $boom, $healthy ), $logger );
$ran2    = $r2->apply( 9, seo_package() );

T::eq( array( 'healthy' ), $ran2, 'the throwing adapter is skipped, the next one still runs' );
T::ok( $logger->hasEvent( 'SEO_ADAPTER_FAILED' ), 'the failure is logged' );

T::group( 'B-6 — adapters are filterable' );

$spy = new SpyAdapter( 'filtered-in', false ); // inactive by default
add_filter(
	'newsdesk_seo_adapters',
	static function ( $active ) use ( $spy ) {
		$active[] = $spy;
		return $active;
	}
);
$r3 = new SeoAdapterResolver( array( new NativeSeoAdapter() ), new FakeLogger() );
$r3->apply( 11, seo_package() );
T::eq( 1, count( $spy->applied ), 'a filter can add an adapter' );
WpTestState::$filters = array();

T::group( 'B-6 — Yoast adapter writes only its verified keys' );

// Simulate Yoast being installed.
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '22.0' );
}
WpTestState::reset();
$yoast = new YoastAdapter();
T::ok( $yoast->isActive(), 'detects Yoast via WPSEO_VERSION' );
$yoast->apply( 21, seo_package() );
T::eq( 'عنوان سئو', get_post_meta( 21, '_yoast_wpseo_title', true ), 'writes _yoast_wpseo_title' );
T::eq( 'توضیح متا برای موتور جست‌وجو.', get_post_meta( 21, '_yoast_wpseo_metadesc', true ), 'writes _yoast_wpseo_metadesc' );
T::eq( 'وردپرس', get_post_meta( 21, '_yoast_wpseo_focuskw', true ), 'writes _yoast_wpseo_focuskw' );
T::eq( 'https://site.test/canonical', get_post_meta( 21, '_yoast_wpseo_canonical', true ), 'writes _yoast_wpseo_canonical' );

T::group( 'B-6 — adapters never clobber an editor’s manual value' );

WpTestState::reset();
update_post_meta( 22, '_yoast_wpseo_title', 'عنوانی که سردبیر نوشته' );
( new YoastAdapter() )->apply( 22, seo_package() );
T::eq( 'عنوانی که سردبیر نوشته', get_post_meta( 22, '_yoast_wpseo_title', true ), 'existing human value is preserved' );
T::eq( 'وردپرس', get_post_meta( 22, '_yoast_wpseo_focuskw', true ), 'but empty fields are still filled' );

T::group( 'B-6 — Rank Math adapter' );

if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '1.0.2' );
}
WpTestState::reset();
$rm = new RankMathAdapter();
T::ok( $rm->isActive(), 'detects Rank Math via RANK_MATH_VERSION' );
$rm->apply( 31, seo_package() );
T::eq( 'عنوان سئو', get_post_meta( 31, 'rank_math_title', true ), 'writes rank_math_title (no underscore prefix)' );
T::eq( 'https://site.test/canonical', get_post_meta( 31, 'rank_math_canonical_url', true ), 'writes rank_math_canonical_url' );
T::eq(
	'وردپرس, امنیت, افزونه',
	get_post_meta( 31, 'rank_math_focus_keyword', true ),
	'secondary keywords are merged into the comma list'
);

T::group( 'B-6 — AIOSEO stays inactive by design (4.x uses its own table)' );

$aio = new AioseoAdapter();
T::ok( ! $aio->isActive(), 'AIOSEO adapter reports inactive' );
WpTestState::reset();
$aio->apply( 41, seo_package() );
T::eq( 0, count( WpTestState::$postMeta ), 'and writes nothing at all' );

$aioSrc = file_get_contents( NEWSDESK_DIR . 'src/Application/Seo/AioseoAdapter.php' );
T::contains( 'aioseo_posts', $aioSrc, 'the reason is documented in the class' );

exit( T::summary() );

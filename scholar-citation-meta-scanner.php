<?php
/**
 * Plugin Name:       Scholar Citation Meta Scanner
 * Description:       Per post/page (off by default): finds every DOI on the page, pulls its metadata from Crossref (then DataCite, then doi.org), and embeds the list as COinS so the Zotero browser button offers all of them as a pick-list. Works alongside Scholar Citation Meta.
 * Version:           1.4.1
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Dillon Mahmoudi
 * Author URI:        https://dillonm.io/
 * Plugin URI:        https://github.com/dillonma/scholar-citation-meta-scanner
 * Update URI:        https://github.com/dillonma/scholar-citation-meta-scanner
 * Text Domain:       scholar-citation-meta-scanner
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Optional settings (wp-config.php):
 *   define( 'SCMS_POST_TYPES', 'post,page' ); // post types that get the on/off switch
 *   define( 'SCMS_MAX_DOIS', 200 );           // safety cap per page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'SCMS_POST_TYPES' ) ) {
	define( 'SCMS_POST_TYPES', 'post,page' );
}
if ( ! defined( 'SCMS_MAX_DOIS' ) ) {
	define( 'SCMS_MAX_DOIS', 200 );
}

const SCMS_CACHE_DAYS     = 30;  // successful lookups, shared across posts
const SCMS_FAIL_CACHE_HRS = 24;  // failed lookups are retried after this
const SCMS_TIME_BUDGET    = 15;  // seconds of lookups per save; the rest continue in the background

function scms_post_types() {
	return array_filter( array_map( 'trim', explode( ',', SCMS_POST_TYPES ) ) );
}

function scms_enabled( $post_id ) {
	return '1' === get_post_meta( $post_id, '_scms_enabled', true );
}

function scms_include_self( $post_id ) {
	return '0' !== get_post_meta( $post_id, '_scms_include_self', true ); // default on
}

/* -------------------------------------------------------------------------
 * DOI extraction
 * ---------------------------------------------------------------------- */

/** Trim punctuation that commonly trails a DOI in prose, keeping balanced parentheses. */
function scms_trim_doi( $doi ) {
	$doi = rtrim( $doi, '.,;:\'"]}' );
	while ( ')' === substr( $doi, -1 ) && substr_count( $doi, '(' ) < substr_count( $doi, ')' ) ) {
		$doi = rtrim( substr( $doi, 0, -1 ), '.,;:' );
	}
	return $doi;
}

/** All unique DOIs in a chunk of HTML: links (doi.org or publisher URLs containing a DOI), "doi:" text, bare DOIs. */
function scms_extract_dois( $html ) {
	$hay = array();
	if ( preg_match_all( '/href\s*=\s*(["\'])(.*?)\1/i', $html, $m ) ) {
		foreach ( $m[2] as $href ) {
			$hay[] = rawurldecode( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
	}
	// Put a space where each tag was, so "…37713.<br>Sociální" doesn't become "…37713.Sociální".
	$plain = wp_strip_all_tags( preg_replace( '/<[^>]+>/', ' $0 ', $html ) );
	$hay[] = rawurldecode( html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

	$found = array();
	foreach ( $hay as $text ) {
		// Crossref's recommended pattern for modern DOIs.
		if ( preg_match_all( '~\b(10\.\d{4,9}/[-._;()/:A-Za-z0-9]+)~', $text, $mm ) ) {
			foreach ( $mm[1] as $doi ) {
				$doi = scms_trim_doi( $doi );
				// Drop path tails some publishers append after the DOI (e.g. /full, /pdf, /abstract).
				$doi = preg_replace( '~/(full|pdf|epdf|abstract|abs|epub|html|fulltext)$~i', '', $doi );
				$key = strtolower( $doi );
				if ( strlen( $doi ) > 7 && ! isset( $found[ $key ] ) ) {
					$found[ $key ] = $doi;
				}
			}
		}
	}
	// A book DOI next to one of its chapter DOIs (10.1201/978…167 and 10.1201/978…167-9): keep the chapter.
	foreach ( array_keys( $found ) as $a ) {
		foreach ( array_keys( $found ) as $b ) {
			if ( $a !== $b && 0 === strpos( $b, $a ) && in_array( substr( $b, strlen( $a ), 1 ), array( '-', '_' ), true ) ) {
				unset( $found[ $a ] );
				break;
			}
		}
	}
	return array_slice( array_values( $found ), 0, (int) SCMS_MAX_DOIS );
}

/**
 * Candidate DOIs for a string that may carry extra junk: publisher URLs often add a slug after
 * the DOI (…/10.1201/9781003480167-9/land-ai-dillon-mahmoudi-alan-wiig). Longest first.
 */
function scms_doi_candidates( $doi ) {
	$c = array( $doi );
	while ( substr_count( $doi, '/' ) > 1 ) {
		$doi = substr( $doi, 0, strrpos( $doi, '/' ) );
		$c[] = $doi;
	}
	// If the tail is clearly a title slug ("land-ai-dillon-mahmoudi"), try without it first.
	if ( count( $c ) > 1 && preg_match( '~/[a-z]+(?:-[a-z]+)+$~', $c[0] ) ) {
		$c = array_merge( array( $c[1] ), array( $c[0] ), array_slice( $c, 2 ) );
	}
	return $c;
}

/** Look up a DOI, trimming trailing path segments until a lookup succeeds. */
function scms_resolve( $doi ) {
	foreach ( scms_doi_candidates( $doi ) as $cand ) {
		$item = scms_lookup( $cand );
		if ( $item ) {
			return $item;
		}
	}
	return null;
}

/* -------------------------------------------------------------------------
 * Metadata lookup (Crossref, falling back to doi.org CSL-JSON for DataCite etc.)
 * ---------------------------------------------------------------------- */

function scms_text( $s ) {
	$s = wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

function scms_first( $v ) {
	return is_array( $v ) ? (string) ( $v[0] ?? '' ) : (string) $v;
}

/** Normalize a Crossref "message" or a CSL-JSON record into one flat array. */
function scms_normalize( $m, $doi ) {
	$type_map = array(
		// Crossref types
		'journal-article' => 'journal-article', 'book-chapter' => 'book-chapter', 'book-section' => 'book-chapter',
		'proceedings-article' => 'proceedings-article', 'book' => 'book', 'monograph' => 'book', 'edited-book' => 'book',
		'reference-book' => 'book', 'report' => 'report', 'dissertation' => 'dissertation', 'posted-content' => 'preprint',
		// CSL types
		'article-journal' => 'journal-article', 'chapter' => 'book-chapter', 'paper-conference' => 'proceedings-article',
		'thesis' => 'dissertation', 'article' => 'preprint', 'dataset' => 'document', 'software' => 'document',
		'webpage' => 'document', 'report-component' => 'report', 'standard' => 'report',
	);
	$type = $type_map[ strtolower( (string) ( $m['type'] ?? '' ) ) ] ?? 'other';

	$title = scms_text( scms_first( $m['title'] ?? '' ) );
	$sub   = scms_text( scms_first( $m['subtitle'] ?? '' ) );
	if ( $sub && false === stripos( $title, $sub ) ) {
		$title .= ': ' . $sub;
	}

	$authors = array();
	foreach ( (array) ( $m['author'] ?? array() ) as $a ) {
		if ( ! empty( $a['family'] ) ) {
			$authors[] = trim( $a['family'] . ( empty( $a['given'] ) ? '' : ', ' . $a['given'] ) );
		} elseif ( ! empty( $a['literal'] ) || ! empty( $a['name'] ) ) {
			$authors[] = $a['literal'] ?? $a['name'];
		}
	}

	$parts = $m['issued']['date-parts'][0] ?? ( $m['published-print']['date-parts'][0] ?? ( $m['published-online']['date-parts'][0] ?? array() ) );
	$parts = array_values( array_filter( array_map( 'intval', (array) $parts ) ) );
	$date  = $parts ? sprintf( '%04d', $parts[0] ) . ( isset( $parts[1] ) ? sprintf( '-%02d', $parts[1] ) : '' ) . ( isset( $parts[2] ) ? sprintf( '-%02d', $parts[2] ) : '' ) : '';

	$volume = (string) ( $m['volume'] ?? '' );
	$issue  = (string) ( $m['issue'] ?? '' );
	$raw    = trim( (string) ( $m['page'] ?? '' ) );
	$pages  = array( '', '' );
	// Some registrars put "Vol. 21 No. 4 (2022): Title" in the page field. Keep real page numbers only.
	if ( preg_match( '/^[A-Za-z]{0,2}\d+[A-Za-z]?(\s*(?:-+|–|—)\s*[A-Za-z]{0,2}\d+[A-Za-z]?)?$/u', $raw ) ) {
		$pages = preg_split( '/\s*(?:-+|–|—)\s*/u', $raw ) + array( '', '' );
	} else {
		if ( '' === $volume && preg_match( '/\bVol(?:ume)?\.?\s*(\d+)/i', $raw, $vm ) ) {
			$volume = $vm[1];
		}
		if ( '' === $issue && preg_match( '/\b(?:No|Nr|Iss(?:ue)?)\.?\s*(\d+)/i', $raw, $im ) ) {
			$issue = $im[1];
		}
	}
	$ret_doi = (string) ( $m['DOI'] ?? $doi );
	if ( 0 === strcasecmp( $ret_doi, $doi ) ) {
		$ret_doi = $doi; // doi.org sometimes upper-cases DOIs
	}

	return array(
		'type'      => $type,
		'title'     => $title,
		'authors'   => $authors,
		'date'      => $date,
		'container' => scms_text( scms_first( $m['container-title'] ?? '' ) ),
		'volume'    => $volume,
		'issue'     => $issue,
		'firstpage' => (string) ( $pages[0] ?: ( $m['article-number'] ?? '' ) ),
		'lastpage'  => (string) ( $pages[1] ?? '' ),
		'issn'      => scms_first( $m['ISSN'] ?? '' ),
		'isbn'      => scms_first( $m['ISBN'] ?? '' ),
		'publisher' => scms_text( $m['publisher'] ?? '' ),
		'doi'       => $ret_doi,
	);
}

function scms_user_agent() {
	return 'ScholarCitationMetaScanner/1.4 (' . home_url() . '; mailto:' . get_option( 'admin_email' ) . ')';
}

/**
 * DataCite REST API lookup (used when Crossref has no record). Returns the record mapped to the
 * Crossref/CSL-style keys the normalizer already understands, or null.
 */
function scms_fetch_datacite( $path, $ua ) {
	$r = wp_remote_get( 'https://api.datacite.org/dois/' . $path, array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/vnd.api+json', 'User-Agent' => $ua ),
	) );
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return null;
	}
	$j = json_decode( wp_remote_retrieve_body( $r ), true );
	$a = $j['data']['attributes'] ?? null;
	if ( ! is_array( $a ) ) {
		return null;
	}

	$title = '';
	$sub   = '';
	foreach ( (array) ( $a['titles'] ?? array() ) as $t ) {
		if ( empty( $t['title'] ) ) {
			continue;
		}
		$tt = $t['titleType'] ?? '';
		if ( '' === $tt && '' === $title ) {
			$title = $t['title'];
		} elseif ( 'Subtitle' === $tt && '' === $sub ) {
			$sub = $t['title'];
		}
	}
	if ( '' === $title ) {
		return null;
	}

	$authors = array();
	foreach ( (array) ( $a['creators'] ?? array() ) as $c ) {
		if ( ! empty( $c['familyName'] ) ) {
			$authors[] = array( 'family' => $c['familyName'], 'given' => $c['givenName'] ?? '' );
		} elseif ( ! empty( $c['name'] ) ) {
			$authors[] = array( 'literal' => $c['name'] );
		}
	}

	$date = '';
	foreach ( (array) ( $a['dates'] ?? array() ) as $d ) {
		if ( 'Issued' === ( $d['dateType'] ?? '' ) && ! empty( $d['date'] ) ) {
			$date = $d['date'];
			break;
		}
	}
	$parts = $date ? array_map( 'intval', explode( '-', substr( $date, 0, 10 ) ) ) : array( (int) ( $a['publicationYear'] ?? 0 ) );

	$cont  = (array) ( $a['container'] ?? array() );
	$page  = trim( (string) ( $cont['firstPage'] ?? '' ) );
	if ( '' !== $page && ! empty( $cont['lastPage'] ) ) {
		$page .= '-' . $cont['lastPage'];
	}
	$issn = array();
	if ( 'ISSN' === strtoupper( (string) ( $cont['identifierType'] ?? '' ) ) && ! empty( $cont['identifier'] ) ) {
		$issn[] = $cont['identifier'];
	}
	foreach ( (array) ( $a['relatedIdentifiers'] ?? array() ) as $ri ) {
		if ( 'ISSN' === strtoupper( (string) ( $ri['relatedIdentifierType'] ?? '' ) ) && 'IsPartOf' === ( $ri['relationType'] ?? '' ) ) {
			$issn[] = $ri['relatedIdentifier'];
		}
	}
	$publisher = $a['publisher'] ?? '';
	if ( is_array( $publisher ) ) {
		$publisher = $publisher['name'] ?? '';
	}
	$type = (string) ( $a['types']['citeproc'] ?? '' );
	if ( '' === $type ) {
		$map  = array( 'JournalArticle' => 'article-journal', 'BookChapter' => 'chapter', 'Book' => 'book', 'ConferencePaper' => 'paper-conference', 'Dissertation' => 'thesis', 'Report' => 'report', 'Preprint' => 'article', 'Dataset' => 'dataset', 'Software' => 'software' );
		$type = $map[ $a['types']['resourceTypeGeneral'] ?? '' ] ?? 'article';
	}
	$container_title = ( 'DataRepository' === ( $cont['type'] ?? '' ) ) ? '' : (string) ( $cont['title'] ?? '' );

	return array(
		'type'            => $type,
		'title'           => $title,
		'subtitle'        => $sub,
		'author'          => $authors,
		'issued'          => array( 'date-parts' => array( array_values( array_filter( $parts ) ) ) ),
		'container-title' => $container_title,
		'volume'          => (string) ( $cont['volume'] ?? '' ),
		'issue'           => (string) ( $cont['issue'] ?? '' ),
		'page'            => $page,
		'ISSN'            => array_values( array_unique( $issn ) ),
		'publisher'       => (string) $publisher,
		'DOI'             => (string) ( $a['doi'] ?? '' ),
	);
}

/** Look up one DOI. Returns a normalized array, or null on failure. Cached in transients. */
function scms_lookup( $doi ) {
	$key    = 'scms_' . md5( strtolower( $doi ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( 'fail' === $cached ) {
		return null;
	}

	$path = implode( '/', array_map( 'rawurlencode', explode( '/', $doi ) ) );
	$item = null;

	$r = wp_remote_get( 'https://api.crossref.org/works/' . $path, array( 'timeout' => 10, 'headers' => array( 'User-Agent' => scms_user_agent() ) ) );
	if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! empty( $j['message'] ) ) {
			$item = scms_normalize( $j['message'], $doi );
		}
	}
	if ( ! $item ) { // Not a Crossref DOI: try DataCite directly (Zenodo, ICPSR, many OJS journals such as ACME).
		$dc = scms_fetch_datacite( $path, scms_user_agent() );
		if ( $dc ) {
			$item = scms_normalize( $dc, $doi );
		}
	}
	if ( ! $item ) { // Other agencies (mEDRA, JaLC, ...): ask doi.org for CSL-JSON.
		$r = wp_remote_get( 'https://doi.org/' . $path, array(
			'timeout'     => 10,
			'redirection' => 5,
			'headers'     => array( 'Accept' => 'application/vnd.citationstyles.csl+json', 'User-Agent' => scms_user_agent() ),
		) );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$j = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( is_array( $j ) && ! empty( $j['title'] ) ) {
				$item = scms_normalize( $j, $doi );
			}
		}
	}

	if ( $item && '' !== $item['title'] ) {
		set_transient( $key, $item, SCMS_CACHE_DAYS * DAY_IN_SECONDS );
		return $item;
	}
	set_transient( $key, 'fail', SCMS_FAIL_CACHE_HRS * HOUR_IN_SECONDS );
	return null;
}

/* -------------------------------------------------------------------------
 * Scanning on save (+ background continuation for long pages)
 * ---------------------------------------------------------------------- */

function scms_process( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || ! scms_enabled( $post_id ) ) {
		return;
	}
	$dois    = scms_extract_dois( $post->post_content );
	$items   = array();
	$failed  = array();
	$pending = array();
	$start   = microtime( true );

	foreach ( $dois as $doi ) {
		$cached = get_transient( 'scms_' . md5( strtolower( $doi ) ) );
		if ( false === $cached && microtime( true ) - $start > SCMS_TIME_BUDGET ) {
			$pending[] = $doi; // out of time: finish in the background
			continue;
		}
		$item = null;
		foreach ( scms_doi_candidates( $doi ) as $cand ) {
			$item = scms_local_record( $cand, $post_id );
			if ( $item ) {
				break;
			}
		}
		if ( $item ) {
			$items[ strtolower( $item['doi'] ?: $doi ) ] = $item;
			continue;
		}
		$item = scms_resolve( $doi );
		if ( $item ) {
			$items[ strtolower( $item['doi'] ) ] = $item;
		} else {
			$failed[] = $doi;
		}
	}

	update_post_meta( $post_id, '_scms_dois', $dois );
	update_post_meta( $post_id, '_scms_items', wp_slash( $items ) );
	update_post_meta( $post_id, '_scms_failed', $failed );
	update_post_meta( $post_id, '_scms_pending', count( $pending ) );
	update_post_meta( $post_id, '_scms_scanned', time() );

	if ( $pending && ! wp_next_scheduled( 'scms_continue', array( $post_id ) ) ) {
		wp_schedule_single_event( time() + 30, 'scms_continue', array( $post_id ) );
	}
}
add_action( 'scms_continue', 'scms_process' );

/* -------------------------------------------------------------------------
 * Editor sidebar box
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes', function () {
	foreach ( scms_post_types() as $pt ) {
		add_meta_box( 'scms_box', 'Zotero DOI list', 'scms_render_box', $pt, 'side', 'default' );
	}
} );

function scms_render_box( $post ) {
	wp_nonce_field( 'scms_save', 'scms_nonce' );
	$on = scms_enabled( $post->ID );
	?>
	<p><label><input type="checkbox" name="scms_enabled" value="1" <?php checked( $on ); ?>> <strong>Scan this page for DOIs</strong></label><br>
	<span class="description">Lets the Zotero browser button save every article cited here. Off by default.</span></p>
	<?php if ( function_exists( 'scm_get_data' ) ) : ?>
		<p><label><input type="checkbox" name="scms_include_self" value="1" <?php checked( scms_include_self( $post->ID ) ); ?>> Put this page's own article first (from Scholar Citation Meta)</label></p>
	<?php endif; ?>
	<?php
	if ( ! $on ) {
		return;
	}
	$dois    = (array) get_post_meta( $post->ID, '_scms_dois', true );
	$items   = (array) get_post_meta( $post->ID, '_scms_items', true );
	$failed  = (array) get_post_meta( $post->ID, '_scms_failed', true );
	$pending = (int) get_post_meta( $post->ID, '_scms_pending', true );
	$scanned = (int) get_post_meta( $post->ID, '_scms_scanned', true );

	if ( ! $scanned ) {
		echo '<p class="description">Click Update, then reload the editor to see what was found.</p>';
		return;
	}
	$dois  = array_filter( $dois );
	$items = array_filter( $items );
	printf(
		'<p>Found <strong>%d</strong> DOI%s; <strong>%d</strong> with metadata. <span class="description">(scanned %s ago)</span></p>',
		count( $dois ),
		1 === count( $dois ) ? '' : 's',
		count( $items ),
		esc_html( human_time_diff( $scanned ) )
	);
	if ( $pending ) {
		echo '<p style="color:#996800">' . (int) $pending . ' still being looked up in the background. Reload in a minute.</p>';
	}
	if ( $items ) {
		echo '<ol style="margin-left:1.4em;font-size:12px;max-height:240px;overflow:auto">';
		foreach ( $items as $it ) {
			$who = $it['authors'] ? strtok( $it['authors'][0], ',' ) . ( count( $it['authors'] ) > 1 ? ' et al.' : '' ) : '';
			printf( '<li>%s %s. <em>%s</em>%s</li>', esc_html( $who ), esc_html( substr( $it['date'], 0, 4 ) ), esc_html( wp_trim_words( $it['title'], 10 ) ), empty( $it['local'] ) ? '' : ' <span title="Metadata from your own post">(your post)</span>' );
		}
		echo '</ol>';
	}
	if ( array_filter( $failed ) ) {
		echo '<p style="color:#b32d2e;font-size:12px">No metadata for: ' . esc_html( implode( ', ', array_filter( $failed ) ) ) . '</p>';
	}
}

add_action( 'save_post', function ( $post_id ) {
	if ( ! isset( $_POST['scms_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scms_nonce'] ) ), 'scms_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	update_post_meta( $post_id, '_scms_enabled', empty( $_POST['scms_enabled'] ) ? '0' : '1' );
	if ( function_exists( 'scm_get_data' ) ) {
		update_post_meta( $post_id, '_scms_include_self', empty( $_POST['scms_include_self'] ) ? '0' : '1' );
	}
	if ( ! empty( $_POST['scms_enabled'] ) ) {
		scms_process( $post_id );
	}
}, 20 );

/* -------------------------------------------------------------------------
 * Front end: COinS spans (OpenURL ContextObjects), which Zotero reads as a list
 * ---------------------------------------------------------------------- */

function scms_coins( $it ) {
	$kv  = array( 'ctx_ver' => 'Z39.88-2004' );
	$add = array(); // repeated keys (authors)
	switch ( $it['type'] ) {
		case 'book-chapter':
			$kv += array( 'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:book', 'rft.genre' => 'bookitem', 'rft.atitle' => $it['title'], 'rft.btitle' => $it['container'] );
			break;
		case 'proceedings-article':
			$kv += array( 'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:book', 'rft.genre' => 'proceeding', 'rft.atitle' => $it['title'], 'rft.btitle' => $it['container'] );
			break;
		case 'book':
			$kv += array( 'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:book', 'rft.genre' => 'book', 'rft.btitle' => $it['title'] );
			break;
		case 'report':
			$kv += array( 'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:book', 'rft.genre' => 'report', 'rft.btitle' => $it['title'] );
			break;
		case 'document': // datasets, software and other non-article DOIs
			$kv += array( 'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:book', 'rft.genre' => 'document', 'rft.title' => $it['title'] );
			break;
		case 'dissertation':
			$kv += array( 'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:dissertation', 'rft.title' => $it['title'], 'rft.inst' => $it['publisher'] );
			break;
		default: // journal articles, preprints, everything else
			$kv += array( 'rft_val_fmt' => 'info:ofi/fmt:kev:mtx:journal', 'rft.genre' => 'article', 'rft.atitle' => $it['title'], 'rft.jtitle' => $it['container'] ?: ( 'preprint' === $it['type'] ? $it['publisher'] : '' ) );
	}
	$kv += array(
		'rft.date'   => $it['date'],
		'rft.volume' => $it['volume'],
		'rft.issue'  => $it['issue'],
		'rft.spage'  => $it['firstpage'],
		'rft.epage'  => $it['lastpage'],
		'rft.issn'   => $it['issn'],
		'rft.isbn'   => $it['isbn'],
		'rft.pub'    => 'dissertation' === $it['type'] ? '' : $it['publisher'],
	);
	foreach ( $it['authors'] as $a ) {
		$add[] = array( 'rft.au', $a );
	}
	if ( $it['doi'] ) {
		$add[] = array( 'rft_id', 'info:doi/' . $it['doi'] );
		$add[] = array( 'rft_id', 'https://doi.org/' . $it['doi'] );
	}
	$pairs = array();
	foreach ( $kv as $k => $v ) {
		if ( '' !== (string) $v ) {
			$pairs[] = $k . '=' . rawurlencode( $v ); // rawurlencode: Zotero turns "+" into a space
		}
	}
	foreach ( $add as $p ) {
		$pairs[] = $p[0] . '=' . rawurlencode( $p[1] );
	}
	return '<span class="Z3988" title="' . esc_attr( implode( '&', $pairs ) ) . '"></span>';
}

/** Convert a Scholar Citation Meta record into this plugin's flat format. */
function scms_from_scm( $d ) {
	$d['date'] = str_replace( '/', '-', (string) $d['date'] );
	$d['date'] = preg_replace_callback( '/-(\d)(?=-|$)/', function ( $m ) {
		return '-0' . $m[1];
	}, $d['date'] );
	$d['issn'] = scms_first( $d['issn'] ?? '' );
	$d['isbn'] = scms_first( $d['isbn'] ?? '' );
	unset( $d['source'] );
	return $d;
}

/**
 * If one of your own posts has this DOI in Scholar Citation Meta, use that post's metadata
 * (including any BibTeX fixes, e.g. missing pages) instead of an outside lookup.
 */
function scms_local_record( $doi, $exclude_id = 0 ) {
	if ( ! function_exists( 'scm_get_data' ) ) {
		return null;
	}
	$ids = get_posts( array(
		'post_type'      => 'any',
		'post_status'    => 'publish',
		'meta_key'       => '_scm_doi', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => $doi,       // phpcs:ignore WordPress.DB.SlowDBQuery
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'post__not_in'   => $exclude_id ? array( $exclude_id ) : array(),
	) );
	if ( ! $ids ) {
		return null;
	}
	$d = scm_get_data( $ids[0] );
	if ( ! $d || '' === $d['title'] || ! $d['authors'] ) {
		return null;
	}
	$d          = scms_from_scm( $d );
	$d['local'] = (int) $ids[0]; // re-read live on display, so later fixes show up without rescanning
	return $d;
}

/** Items to expose on a post: optionally the page's own article first, then the scanned DOIs. */
function scms_items_for( $post_id ) {
	$items = array();
	if ( scms_include_self( $post_id ) && function_exists( 'scm_get_data' ) ) {
		$own = scm_get_data( $post_id );
		if ( $own && '' !== $own['title'] && $own['authors'] ) {
			$own = scms_from_scm( $own );
			$items[ strtolower( $own['doi'] ?: 'self' ) ] = $own;
		}
	}
	foreach ( (array) get_post_meta( $post_id, '_scms_items', true ) as $k => $it ) {
		if ( ! is_array( $it ) || isset( $items[ $k ] ) ) {
			continue;
		}
		if ( ! empty( $it['local'] ) && function_exists( 'scm_get_data' ) ) {
			$live = scm_get_data( (int) $it['local'] );
			if ( $live && '' !== $live['title'] && $live['authors'] ) {
				$it = scms_from_scm( $live );
			}
		}
		$items[ $k ] = $it;
	}
	return apply_filters( 'scms_items', $items, $post_id );
}

add_action( 'wp_footer', function () {
	if ( ! is_singular( scms_post_types() ) ) {
		return;
	}
	$id = get_queried_object_id();
	if ( ! scms_enabled( $id ) ) {
		return;
	}
	$items = scms_items_for( $id );
	if ( ! $items ) {
		return;
	}
	echo "\n<!-- Scholar Citation Meta Scanner: " . count( $items ) . " items for Zotero -->\n<div class=\"scms-coins\" style=\"display:none\" aria-hidden=\"true\">";
	foreach ( $items as $it ) {
		echo scms_coins( $it ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in scms_coins()
	}
	echo "</div>\n";
} );

register_deactivation_hook( __FILE__, function () {
	wp_unschedule_hook( 'scms_continue' );
} );

/* -------------------------------------------------------------------------
 * Updates from GitHub Releases (uses the "Update URI" header, WordPress 5.8+)
 * ---------------------------------------------------------------------- */

const SCMS_GITHUB_REPO = 'dillonma/scholar-citation-meta-scanner';

/** Latest GitHub release as ['version','package','url','notes','date'], cached for 6 hours. */
function scms_latest_release( $force = false ) {
	$key = 'scms_gh_release';
	$rel = $force ? false : get_site_transient( $key );
	if ( false !== $rel ) {
		return $rel ?: null;
	}
	$r = wp_remote_get( 'https://api.github.com/repos/' . SCMS_GITHUB_REPO . '/releases/latest', array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url() ),
	) );
	$rel = array();
	if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
			if ( preg_match( '/^scholar-citation-meta-scanner(-[\d.]+)?\.zip$/', $a['name'] ?? '' ) ) {
				$rel = array(
					'version' => ltrim( (string) $j['tag_name'], 'vV' ),
					'package' => $a['browser_download_url'],
					'url'     => $j['html_url'],
					'notes'   => (string) ( $j['body'] ?? '' ),
					'date'    => (string) ( $j['published_at'] ?? '' ),
				);
				break;
			}
		}
	}
	if ( ! $rel ) {
		// API unavailable or rate-limited (60 requests/hour per server IP): follow the
		// github.com/…/releases/latest redirect instead and build the asset URL from the tag.
		$h   = wp_remote_head( 'https://github.com/' . SCMS_GITHUB_REPO . '/releases/latest', array( 'timeout' => 10, 'redirection' => 0 ) );
		$loc = is_wp_error( $h ) ? '' : (string) wp_remote_retrieve_header( $h, 'location' );
		if ( preg_match( '~/releases/tag/(v?([\d.]+))$~', $loc, $m ) ) {
			$rel = array(
				'version' => $m[2],
				'package' => 'https://github.com/' . SCMS_GITHUB_REPO . '/releases/download/' . $m[1] . '/scholar-citation-meta-scanner-' . $m[2] . '.zip',
				'url'     => $loc,
				'notes'   => 'See ' . $loc,
				'date'    => '',
			);
		}
	}
	set_site_transient( $key, $rel, $rel ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $rel ?: null;
}

add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}
	$rel = scms_latest_release();
	if ( ! $rel ) {
		return $update;
	}
	return array(
		'slug'    => dirname( $plugin_file ),
		'version' => $rel['version'],
		'url'     => $rel['url'],
		'package' => $rel['package'],
	);
}, 10, 3 );

// "View details" popup on the Plugins/Updates screens.
add_filter( 'plugins_api', function ( $res, $action, $args ) {
	if ( 'plugin_information' !== $action || dirname( plugin_basename( __FILE__ ) ) !== ( $args->slug ?? '' ) ) {
		return $res;
	}
	$rel = scms_latest_release();
	if ( ! $rel ) {
		return $res;
	}
	$data = get_file_data( __FILE__, array( 'Name' => 'Plugin Name', 'Description' => 'Description', 'Author' => 'Author', 'Requires' => 'Requires at least', 'RequiresPHP' => 'Requires PHP' ) );
	return (object) array(
		'name'          => $data['Name'],
		'slug'          => $args->slug,
		'version'       => $rel['version'],
		'author'        => $data['Author'],
		'homepage'      => 'https://github.com/' . SCMS_GITHUB_REPO,
		'requires'      => $data['Requires'],
		'requires_php'  => $data['RequiresPHP'],
		'last_updated'  => $rel['date'],
		'download_link' => $rel['package'],
		'sections'      => array(
			'description' => esc_html( $data['Description'] ),
			'changelog'   => wpautop( esc_html( $rel['notes'] ) ),
		),
	);
}, 10, 3 );

// Forget the cached release after this plugin is updated.
add_action( 'upgrader_process_complete', function () {
	delete_site_transient( 'scms_gh_release' );
} );

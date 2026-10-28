<?php
/**
 * Mini Lion Galaxy — YouTube autopilot feed.
 *
 * Returns the channel's latest videos as JSON for the Explore page, so new
 * uploads appear on the site automatically (re-checked every 15 minutes).
 * No API key needed. Works on any host with PHP + cURL.
 *
 * 1. Tries YouTube's RSS feed (has dates and view counts).
 * 2. If YouTube's feed is down, reads the channel's Videos and Shorts tabs
 *    and gets each title from YouTube's oEmbed service.
 * 3. If YouTube can't be reached at all, serves the last good copy.
 */

$mlg_channel_id = 'UC68V0u0qcaH_9tuQJs2MBhQ';
$mlg_handle     = 'miniliongalaxy';
$mlg_cache_ttl  = 15 * 60;
$mlg_max        = 60;

$mlg_cache_file = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'mlg_videos_' . md5( $mlg_channel_id ) . '.json';

if ( ! function_exists( 'mlg_fetch_url' ) ) {
	function mlg_fetch_url( $url ) {
		$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 12,
				CURLOPT_USERAGENT      => $ua,
				CURLOPT_HTTPHEADER     => array( 'Accept-Language: en-US,en;q=0.9' ),
				CURLOPT_COOKIE         => 'CONSENT=YES+1; SOCS=CAI',
			) );
			$body = curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			return ( 200 === $code && $body ) ? $body : false;
		}
		if ( ini_get( 'allow_url_fopen' ) ) {
			$ctx = stream_context_create( array( 'http' => array(
				'timeout'    => 12,
				'user_agent' => $ua,
				'header'     => "Accept-Language: en-US,en;q=0.9\r\nCookie: CONSENT=YES+1; SOCS=CAI\r\n",
			) ) );
			return @file_get_contents( $url, false, $ctx );
		}
		return false;
	}
}

/** Way 1: the RSS feed. */
if ( ! function_exists( 'mlg_from_rss' ) ) {
	function mlg_from_rss( $channel_id ) {
		$raw = mlg_fetch_url( 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channel_id );
		if ( ! $raw ) {
			return false;
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $raw );
		libxml_use_internal_errors( $prev );
		if ( ! $xml ) {
			return false;
		}
		$videos = array();
		foreach ( $xml->entry as $entry ) {
			$yt    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
			$media = $entry->children( 'http://search.yahoo.com/mrss/' );
			$id    = (string) $yt->videoId;
			if ( '' === $id ) {
				continue;
			}
			$views = 0;
			if ( isset( $media->group->community->statistics ) ) {
				$views = (int) $media->group->community->statistics->attributes()->views;
			}
			$videos[] = array(
				'id'        => $id,
				'title'     => (string) $entry->title,
				'published' => (string) $entry->published,
				'views'     => $views,
			);
		}
		return $videos;
	}
}

/** Way 2: the channel's Videos and Shorts tabs, titles via oEmbed. */
if ( ! function_exists( 'mlg_from_channel_page' ) ) {
	function mlg_from_channel_page( $handle, $channel_id, $known_titles, $max ) {
		$ids     = array();
		$reached = false;
		foreach ( array( 'videos', 'shorts' ) as $tab ) {
			$html = mlg_fetch_url( 'https://www.youtube.com/@' . $handle . '/' . $tab );
			if ( ! $html ) {
				continue;
			}
			// Only trust the page if it really is this channel.
			if ( false === strpos( $html, $channel_id ) ) {
				continue;
			}
			$reached = true;
			if ( preg_match_all( '/"(?:videoId|contentId)":"([\w-]{11})"/', $html, $m ) ) {
				foreach ( $m[1] as $id ) {
					$ids[ $id ] = true;
				}
			}
		}
		if ( ! $reached ) {
			return false;
		}

		$videos = array();
		foreach ( array_slice( array_keys( $ids ), 0, $max ) as $id ) {
			$title = isset( $known_titles[ $id ] ) ? $known_titles[ $id ] : '';
			if ( '' === $title ) {
				$o = mlg_fetch_url( 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $id ) );
				$o = $o ? json_decode( $o, true ) : null;
				if ( ! $o || empty( $o['title'] ) ) {
					continue; // private, deleted or not from this channel
				}
				if ( ! empty( $o['author_url'] ) && false === stripos( $o['author_url'], $handle ) && false === strpos( $o['author_url'], $channel_id ) ) {
					continue;
				}
				$title = $o['title'];
			}
			$videos[] = array(
				'id'        => $id,
				'title'     => $title,
				'published' => '',
				'views'     => 0,
			);
		}
		return $videos;
	}
}

$mlg_cached = is_file( $mlg_cache_file ) ? @file_get_contents( $mlg_cache_file ) : '';
$mlg_fresh  = $mlg_cached && ( time() - filemtime( $mlg_cache_file ) ) < $mlg_cache_ttl;
$mlg_payload = $mlg_fresh ? $mlg_cached : '';

if ( ! $mlg_payload ) {
	$mlg_known = array();
	$mlg_old   = $mlg_cached ? json_decode( $mlg_cached, true ) : null;
	if ( $mlg_old && ! empty( $mlg_old['videos'] ) ) {
		foreach ( $mlg_old['videos'] as $v ) {
			$mlg_known[ $v['id'] ] = $v['title'];
		}
	}

	$mlg_source = 'rss';
	$mlg_videos = mlg_from_rss( $mlg_channel_id );
	if ( false === $mlg_videos || ! $mlg_videos ) {
		$mlg_source = 'channel';
		$mlg_videos = mlg_from_channel_page( $mlg_handle, $mlg_channel_id, $mlg_known, $mlg_max );
	}

	if ( false !== $mlg_videos ) {
		$mlg_payload = json_encode( array(
			'channel' => $mlg_channel_id,
			'source'  => $mlg_source,
			'updated' => gmdate( 'c' ),
			'videos'  => array_slice( $mlg_videos, 0, $mlg_max ),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		@file_put_contents( $mlg_cache_file, $mlg_payload, LOCK_EX );
	} elseif ( $mlg_cached ) {
		$mlg_payload = $mlg_cached; // YouTube unreachable right now: last good copy
	}
}

if ( ! headers_sent() ) {
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Cache-Control: public, max-age=300' );
	header( 'X-Robots-Tag: noindex' );
	// Public video list: let copies of the site on other hosts (e.g. Cloudflare) read it.
	header( 'Access-Control-Allow-Origin: *' );
}

if ( $mlg_payload ) {
	echo $mlg_payload;
} else {
	if ( ! headers_sent() ) {
		http_response_code( 502 );
	}
	echo '{"videos":[],"error":"feed_unavailable"}';
}

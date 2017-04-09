<?php

class PdfBookHooks {

	public static function onRegistration() {
		global $wgLogTypes, $wgLogNames, $wgLogHeaders, $wgLogActions;
		$wgLogTypes[]             = 'pdf';
		$wgLogNames  ['pdf']      = 'pdflogpage';
		$wgLogHeaders['pdf']      = 'pdflogpagetext';
		$wgLogActions['pdf/book'] = 'pdflogentry';
	}

	/**
	 * Perform the export operation
	 */
	public static function onUnknownAction( $action, $article ) {
		global $wgOut, $wgUser, $wgParser, $wgRequest, $wgAjaxComments, $wgPdfBookDownload;
		global $wgServer, $wgArticlePath, $wgScriptPath, $wgUploadPath, $wgUploadDirectory, $wgScript;

		if( $action == 'pdfbook' ) {
			$title = $article->getTitle();
			$book = $title->getText();
			$opt = ParserOptions::newFromUser( $wgUser );

			// Log the export
			$msg = wfMessage( 'pdfbook-log', $wgUser->getUserPage()->getPrefixedText() )->text();
			$log = new LogPage( 'pdf', false );
			$log->addEntry( 'book', $article->getTitle(), $msg );

			// Initialise PDF variables
			$format   = self::setProperty( 'format', '', '' );
			$nothumbs = self::setProperty( 'nothumbs', '', '' );
			$notitle  = self::setProperty( 'notitle', '', '' );
			$comments = $wgAjaxComments ? self::setProperty( 'comments', '', false ) : '';
			$layout   = $format == 'single' ? '--webpage' : '--firstpage toc';
			$charset  = self::setProperty( 'Charset',     'utf8' );
			$left     = self::setProperty( 'LeftMargin',  '1cm' );
			$right    = self::setProperty( 'RightMargin', '1cm' );
			$top      = self::setProperty( 'TopMargin',   '1cm' );
			$bottom   = self::setProperty( 'BottomMargin','1cm' );
			$font     = self::setProperty( 'Font',        'Arial' );
			$size     = self::setProperty( 'FontSize',    '8' );
			$ls       = self::setProperty( 'LineSpacing', 1 );
			$linkcol  = self::setProperty( 'LinkColour',  '217A28' );
			$levels   = self::setProperty( 'TocLevels',   '2' );
			$exclude  = self::setProperty( 'Exclude',     array() );
			$width    = self::setProperty( 'Width',       '' );
			$options  = self::setProperty( 'Options',     '' );
			$width    = $width ? "--browserwidth $width" : '';
			if( !is_array( $exclude ) ) $exclude = preg_split( '\\s*,\\s*', $exclude );
 
			// Generate a list of the articles involved in this doc
			// - this is unconditional so that it can be used in cache key generation

			// Select articles from members if a category or links in content if not
			if( $format == 'single' || $format == 'html' ) $articles = array( $title );
			else {
				$articles = array();
				if( $title->getNamespace() == NS_CATEGORY ) {
					$db     = wfGetDB( DB_SLAVE );
					$cat    = $db->addQuotes( $title->getDBkey() );
					$result = $db->select(
						'categorylinks',
						'cl_from',
						"cl_to = $cat",
						'PdfBook',
						array( 'ORDER BY' => 'cl_sortkey' )
					);
					if( $result instanceof ResultWrapper ) $result = $result->result;
					while( $row = $db->fetchRow( $result ) ) $articles[] = Title::newFromID( $row[0] );
				} else {
					$text = $article->getPage()->getContent()->getNativeData();
					$text = $wgParser->preprocess( $text, $title, $opt );
					if( preg_match_all( "/^\\*\\s*\\[{2}\\s*([^\\|\\]]+)\\s*.*?\\]{2}/m", $text, $links ) ) {
						foreach( $links[1] as $link ) $articles[] = Title::newFromText( $link );
					}
				}
			}

 			// Create a cache filename from the query-string parameters and the revision ID's of all the source articles
 			$cache = json_encode( $_GET );
 			foreach( $articles as $title ) $cache .= '-' . $title->getLatestRevID();
			$cache = $wgUploadDirectory . '/pdf-book-cache-' . md5( $cache );

			// If the file doesn't exist, render the content now
			if( !file_exists( $cache ) ) {

				// Format the article(s) as a single HTML document with absolute URL's
				$html = '<!DOCTYPE html><html><body><link rel="stylesheet" href="/load.php?debug=false&amp;lang=he&amp;modules=mediawiki.legacy.commonPrint%2Cshared"/>';
        $html .= '<style>.noprint {display: none}</style>';
				$wgArticlePath = $wgServer . $wgArticlePath;
				$wgPdfBookTab  = false;
				$wgScriptPath  = $wgServer . $wgScriptPath;
				$wgUploadPath  = $wgServer . $wgUploadPath;
				$wgScript      = $wgServer . $wgScript;
				foreach( $articles as $title ) {
					$ttext = $title->getPrefixedText();
					if( !in_array( $ttext, $exclude ) ) {
						$article = new Article( $title );
						$text    = $article->getPage()->getContent()->getNativeData();
						$text    = preg_replace( "/<!--([^@]+?)-->/s", "@@" . "@@$1@@" . "@@", $text );            // preserve HTML comments
						if( $format != 'single' ) $text .= "__NOTOC__";
						$opt->setEditSection( false );                                                             // remove section-edit links
						$out     = $wgParser->parse( $text, $title, $opt, true, true );
						$text    = $out->getText();
						if( $format == 'html' ) {
							$text    = preg_replace( "|(<img[^>]+?src=\")(/.+?>)|", "$1$wgServer$2", $text );      // make image urls absolute
						} else {
							$pUrl    = parse_url( $wgScriptPath ) ;
              if (isset($pUrl['path'])) {
							  $imgpath = str_replace( '/' , '\/', $pUrl['path'] . '/' . basename( $wgUploadDirectory ) ) ; // the image's path
							  $text    = preg_replace( "|(<img[^>]+?src=\"$imgpath)(/.+?>)|", "<img src=\"$wgUploadDirectory$2", $text );
              }
						}
						if( $nothumbs == 'true') $text = preg_replace( "|images/thumb/(\w+/\w+/[\w\.\-]+).*\"|", "images/$1\"", $text ); // Convert image links from thumbnail to full-size
						$text    = preg_replace( "|<div\s*class=['\"]?noprint[\"']?>.+?</div>|s", "", $text );     // non-printable areas
						$text    = preg_replace( "|@{4}([^@]+?)@{4}|s", "<!--$1-->", $text );                      // HTML comments hack
						$ttext   = basename( $ttext );
						$h1      = $notitle ? "" : "<center><h1>$ttext</h1></center>";

						// Add comments if selected and AjaxComments is installed
						if( $comments ) {
							$comments = $wgAjaxComments->onUnknownAction( 'ajaxcommentsinternal', $article );
						}

						$html .= "$h1$text\n$comments";
					}
				}
        $html .= "</body></html>";

				// Build the cache file
				file_put_contents( $cache, $html );
      }

			// Output the cache file
			$wgOut->disable();
			echo file_get_contents( $cache );

			return false;
		}
		return true;
	}

	/**
	 * Return a sanitised property for htmldoc using global, request or passed default
	 */
	private static function setProperty( $name, $val, $prefix = 'pdf' ) {
		global $wgRequest;
		if( $wgRequest->getText( "$prefix$name" ) ) $val = $wgRequest->getText( "$prefix$name" );
		if( $wgRequest->getText( "amp;$prefix$name" ) ) $val = $wgRequest->getText( "amp;$prefix$name" ); // hack to handle ampersand entities in URL
		if( isset( $GLOBALS["wgPdfBook$name"] ) ) $val = $GLOBALS["wgPdfBook$name"];
		return preg_replace( '|[^-_:.a-z0-9]|i', '', $val );
	}

	/**
	 * Add PDF to actions tabs in MonoBook based skins
	 */
	public static function onSkinTemplateTabs( $skin, &$actions) {
		global $wgPdfBookTab, $wgUser;
		if( $wgPdfBookTab && $wgUser->isLoggedIn() ) {
			$actions['pdfbook'] = array(
				'class' => false,
				'text' => wfMessage( 'pdfbook-action' )->text(),
				'href' => self::actionLink( $skin )
			);
		}
		return true;
	}

	/**
	 * Add PDF to actions tabs in vector based skins
	 */
	public static function onSkinTemplateNavigation( $skin, &$actions ) {
		global $wgPdfBookTab, $wgUser;
		if( $wgPdfBookTab && $wgUser->isLoggedIn() ) {
			$actions['views']['pdfbook'] = array(
				'class' => false,
				'text' => wfMessage( 'pdfbook-action' )->text(),
				'href' => self::actionLink( $skin )
			);
		}
		return true;
	}

	/**
	 * Get the URL for the action link
	 */
	public static function actionLink( $skin ) {
		$qs = 'action=pdfbook&format=single';
		foreach( $_REQUEST as $k => $v ) if( $k != 'title' ) $qs .= "&$k=$v";
		return $skin->getTitle()->getLocalURL( $qs );
	}
}

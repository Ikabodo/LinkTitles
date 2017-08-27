<?php
/**
 * The LinkTitles\Linker class does the heavy linking for the extension.
 *
 * Copyright 2012-2017 Daniel Kraus <bovender@bovender.de> ('bovender')
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 *
 * @author Daniel Kraus <bovender@bovender.de>
 */
namespace LinkTitles;

/**
 * Performs the actual linking of content to existing pages.
 */
class Linker {
	/**
	 * LinkTitles configuration.
	 *
	 * @var Config $config
	 */
	public $config;

	/**
	 * The string representation of the title object for the potential target page
	 * that is currently being processed.
	 *
	 * This is an instance variable (rather than a local method variable) so it
	 * can be accessed in the preg_replace_callback callbacks.
	 *
	 * @var String $targetTitleString
	 */
	private $targetTitleText;

	/**
	 * Constructs a new instance of the Linker class.
	 *
	 * @param Config $config LinkTitles configuration object.
	 */
	public function __construct( Config &$config ) {
		$this->config = $config;
	}

	/**
	 * Core function of the extension, performs the actual parsing of the content.
	 *
	 * This method receives a Title object and the string representation of the
	 * source page. It does not work on a WikiPage object directly because the
	 * callbacks in the Extension class do not always get a WikiPage object in the
	 * first place.
	 *
	 * @param \Title &$title Title object for the current page.
	 * @param String $text String that holds the article content
	 * @return String with links to target pages
	 */
	public function linkContent( \Title &$title, &$text ) {

		( $this->config->firstOnly ) ? $limit = 1 : $limit = -1;
		$limitReached = false;
		$newText = $text;

		$splitter = Splitter::default( $this->config );
		$targets = Targets::default( $title, $this->config );

		// Iterate through the target page titles
		foreach( $targets->queryResult as $row ) {
			$target = new Target( $row->page_namespace, $row->page_title, $this->config );

			// Don't link current page and don't link if the target page redirects
			// to the current page or has the __NOAUTOLINKTARGET__ magic word
			// (as required by the actual LinkTitles configuration).
			if ( $target->isSameTitle( $title ) || !$target->mayLinkTo( $title ) ) {
				continue;
			}

			// Split the page content by non-linkable sections.
			// Credits to inhan @ StackOverflow for suggesting preg_split.
			// See http://stackoverflow.com/questions/10672286
			$arr = $splitter->split( $newText );
			$count = 0;

			// Cache the target title text for the regex callbacks
			$this->targetTitleText = $target->getTitleText();

			// Even indexes will point to sections of the text that may be linked
			for ( $i = 0; $i < count( $arr ); $i += 2 ) {
				$arr[$i] = preg_replace_callback( $target->getCaseSensitiveRegex(),
					array( $this, 'simpleModeCallback'),
					$arr[$i], $limit, $count );
				if ( $this->config->firstOnly && ( $count > 0 ) ) {
					$limitReached = true;
					break;
				};
			};
			$newText = implode( '', $arr );

			// If smart mode is turned on, the extension will perform a second
			// pass on the page and add links with aliases where the case does
			// not match.
			if ( $this->config->smartMode && !$limitReached ) {
				if ( $count > 0 ) {
					// Split the text again because it was changed in the first pass.
					$arr = $splitter->split( $newText );
				}

				for ( $i = 0; $i < count( $arr ); $i+=2 ) {
					// even indexes will point to text that is not enclosed by brackets
					$arr[$i] = preg_replace_callback( $target->getCaseInsensitiveRegex(),
						array( $this, 'smartModeCallback'),
						$arr[$i], $limit, $count );
					if ( $this->config->firstOnly && ( $count > 0  )) {
						break;
					};
				};
				$newText = implode( '', $arr );
			} // $wgLinkTitlesSmartMode
		}; // foreach $res as $row

		return $newText;
	}

	/**
	 * Callback for preg_replace_callback in simple mode.
	 *
	 * @param array $matches Matches provided by preg_replace_callback
	 * @return string Target page title with or without link markup
	 */
	private function simpleModeCallback( array $matches ) {
		return '[[' . $matches[0] . ']]';
	}

	/**
	 * Callback function for use with preg_replace_callback.
	 * This essentially performs a case-sensitive comparison of the
	 * current page title and the occurrence found on the page; if
	 * the cases do not match, it builds an aliased (piped) link.
	 * If $wgCapitalLinks is set to true, the case of the first
	 * letter is ignored by MediaWiki and we don't need to build a
	 * piped link if only the case of the first letter is different.
	 *
	 * @param array $matches Matches provided by preg_replace_callback
	 * @return string Target page title with or without link markup
	 */
	private function smartModeCallback( array $matches ) {
		if ( $this->config->capitalLinks ) {
			// With $wgCapitalLinks set to true we have a slightly more
			// complicated version of the callback than if it were false;
			// we need to ignore the first letter of the page titles, as
			// it does not matter for linking.
			if ( strcmp( substr( $this->targetTitleText, 1 ), substr( $matches[ 0 ], 1) ) == 0 ) {
				// Case-sensitive match: no need to bulid piped link.
				return '[[' . $matches[ 0 ]  . ']]';
			} else  {
				// Case-insensitive match: build piped link.
				return '[[' . $this->targetTitleText . '|' . $matches[ 0 ] . ']]';
			}
		} else {
			// If $wgCapitalLinks is false, we can use the simple variant
			// of the callback function.
			if ( strcmp( $this->targetTitleText, $matches[ 0 ] ) == 0 ) {
				// Case-sensitive match: no need to bulid piped link.
				return '[[' . $matches[ 0 ] . ']]';
			} else  {
				// Case-insensitive match: build piped link.
				return '[[' . $this->targetTitleText . '|' . $matches[ 0 ] . ']]';
			}
		}
	}
}

// vim: ts=2:sw=2:noet:comments^=\:///
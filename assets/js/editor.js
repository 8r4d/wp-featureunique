( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.data ) {
		return;
	}

	var addFilter = wp.hooks.addFilter;
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useSelect = wp.data.useSelect;

	function withFeatureUniqueWarning( OriginalComponent ) {
		return function ( props ) {
			var original = createElement( OriginalComponent, props );
			var data = window.featureUniqueData;

			if ( ! data || ! data.duplicates ) {
				return original;
			}

			var selected = useSelect( function ( select ) {
				var editor = select( 'core/editor' );
				return {
					featuredMediaId: editor.getEditedPostAttribute( 'featured_media' ),
					postId: editor.getCurrentPostId(),
				};
			}, [] );

			if ( ! selected.featuredMediaId ) {
				return original;
			}

			var usage = data.duplicates[ selected.featuredMediaId ];

			if ( ! usage ) {
				return original;
			}

			var others = usage.filter( function ( post ) {
				return post.id !== selected.postId;
			} );

			if ( 0 === others.length ) {
				return original;
			}

			var count = others.length;
			var label =
				count === 1
					? 'Already used as the featured image on 1 other post:'
					: 'Already used as the featured image on ' + count + ' other posts:';

			var warning = createElement(
				'div',
				{ className: 'feature-unique-block-warning' },
				createElement( 'p', {}, label ),
				createElement(
					'ul',
					{},
					others.map( function ( post ) {
						return createElement(
							'li',
							{ key: post.id },
							createElement( 'a', { href: post.editLink, target: '_blank', rel: 'noreferrer' }, post.title )
						);
					} )
				)
			);

			return createElement( Fragment, {}, original, warning );
		};
	}

	addFilter( 'editor.PostFeaturedImage', 'feature-unique/warning', withFeatureUniqueWarning );
} )( window.wp );

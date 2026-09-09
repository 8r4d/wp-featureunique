( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.data ) {
		return;
	}

	var addFilter = wp.hooks.addFilter;
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useSelect = wp.data.useSelect;

	function getCurrentPostId() {
		try {
			return wp.data.select( 'core/editor' ).getCurrentPostId();
		} catch ( e ) {
			return 0;
		}
	}

	// Every post (including the current one, if already set) using this
	// attachment as a featured image, minus the post currently being edited.
	function getOtherUses( attachmentId ) {
		var data = window.featureUniqueData;

		if ( ! data || ! data.usage || ! data.usage[ attachmentId ] ) {
			return [];
		}

		var postId = getCurrentPostId();

		return data.usage[ attachmentId ].filter( function ( post ) {
			return post.id !== postId;
		} );
	}

	function warningLabel( count ) {
		return count === 1
			? 'Already used as the featured image on 1 other post:'
			: 'Already used as the featured image on ' + count + ' other posts:';
	}

	/**
	 * Sidebar "Featured Image" panel: warns once an image has been set,
	 * if it's already used as the featured image elsewhere.
	 */
	function withFeatureUniqueWarning( OriginalComponent ) {
		return function ( props ) {
			var original = createElement( OriginalComponent, props );

			var featuredMediaId = useSelect( function ( select ) {
				return select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
			}, [] );

			if ( ! featuredMediaId ) {
				return original;
			}

			var others = getOtherUses( featuredMediaId );

			if ( 0 === others.length ) {
				return original;
			}

			var warning = createElement(
				'div',
				{ className: 'feature-unique-block-warning' },
				createElement( 'p', {}, warningLabel( others.length ) ),
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

	/**
	 * "Set/Replace Featured Image" picker: the block editor opens the same
	 * classic wp.media modal used everywhere else (Insert Media, galleries,
	 * etc). Its single-selection sidebar is always an
	 * wp.media.view.Attachment.Details instance, so patching its render is
	 * the one place that reliably covers the picker regardless of which
	 * frame/state opened it.
	 */
	if ( wp.media && wp.media.view && wp.media.view.Attachment && wp.media.view.Attachment.Details ) {
		var originalDetailsRender = wp.media.view.Attachment.Details.prototype.render;

		wp.media.view.Attachment.Details.prototype.render = function () {
			originalDetailsRender.apply( this, arguments );

			this.$el.find( '.feature-unique-picker-warning' ).remove();

			var others = this.model ? getOtherUses( this.model.get( 'id' ) ) : [];

			if ( 0 === others.length ) {
				return this;
			}

			var doc = this.el.ownerDocument || document;
			var wrap = doc.createElement( 'div' );
			wrap.className = 'feature-unique-picker-warning';

			var p = doc.createElement( 'p' );
			p.textContent = warningLabel( others.length );
			wrap.appendChild( p );

			var ul = doc.createElement( 'ul' );
			others.forEach( function ( post ) {
				var li = doc.createElement( 'li' );
				var a = doc.createElement( 'a' );
				a.href = post.editLink;
				a.target = '_blank';
				a.rel = 'noreferrer';
				a.textContent = post.title;
				li.appendChild( a );
				ul.appendChild( li );
			} );
			wrap.appendChild( ul );

			this.$el.prepend( wrap );

			return this;
		};
	}
} )( window.wp );

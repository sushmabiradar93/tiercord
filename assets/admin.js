/* TierCord admin: confirm dangerous actions (links/buttons with data-tiercord-confirm). */
( function () {
	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest( '[data-tiercord-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-tiercord-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );

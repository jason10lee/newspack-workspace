const getDisplayValue = ( field, value ) => {
	if ( ! value || ( Array.isArray( value ) && ! value.length ) ) {
		return null;
	}
	if ( field.options?.length ) {
		if ( Array.isArray( value ) ) {
			value = value.map(
				v => field.options.find( o => o.value === v )?.label || v
			);
		}
		value = field.options.find( o => o.value === value )?.label || value;
	}
	if ( field.type === 'date' ) {
		return new Date( value ).toLocaleDateString();
	}
	if ( field.type === 'datetime' ) {
		return new Date( value ).toLocaleString();
	}
	if ( Array.isArray( value ) ) {
		return value.join( ', ' );
	}
	return value;
};

export default ( { field, item } ) => {
	const value = getDisplayValue( field, item[ field.slug ] );
	const style = {
		whiteSpace: 'normal',
	};
	if ( field.slug === 'title' ) {
		style.width = 200;
	}
	return <div style={ style }>{ value }</div>;
};

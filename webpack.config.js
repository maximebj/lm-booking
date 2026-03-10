const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
		frontend: path.resolve( __dirname, 'src/frontend/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};

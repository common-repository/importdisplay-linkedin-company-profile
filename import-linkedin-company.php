<?php
/*
Plugin Name: Import/Display LinkedIn Company Profile
Plugin URI: http://www.sixspokemedia.com
Description: Import and display linkedin company profile. 
It requires Contact Form 7 (http://wordpress.org/plugins/contact-form-7/). 

Here are the steps to import and display company profiles:
1. the import process is initiated by public user submitting Contact Form which must have a linkedin-url field;
2. this plugin then will create a new post with post type "Company Profile"
3. a WordPress administration click "import" link
4. this plugin take the admin to LinkedIn to login. The admin can login with any linkedin account because LinkedIn API requires a live user token which is only generated by a live user
5. if the login fails, the admin will be prompted the failure and suggested to click "import" link again
6. (TODO) if linkedin profile had been imported before, the profile won't be re-imported; and the admin will be prompted that the profile has been imported before. 
    In order to re-import, please delete the main content of the Company Profile and click on "import" link.
    Or the plugin confirm with the admin and re-import and overwrite previous import
7. the plugin pulls company profile from LinkedIn and overwrites the main content of the post
8. the admin publish the post

Author: Neo Wang
Version: 1.0.0
Author URI: http://www.sixspokemedia.com
*/

//register new post type: Company Profile
add_action( 'init', 'create_6sm_company_profile' );
function create_6sm_company_profile() {
	register_post_type( '6sm_company_profile',
		array(
			'labels' => array(
				'name' => __( 'Company Profiles' ),
				'singular_name' => __( 'Company Profile' )
			),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'company-profiles'),
            'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'custom-fields'),
            'taxonomies' => array('category','post_tag')
		)
	);
}
// Show posts of 'post', 'page' and 'movie' post types on home page
add_action( 'pre_get_posts', 'add_6sm_company_profile_to_query' );

function add_6sm_company_profile_to_query( $query ) {
	if ( is_home() && $query->is_main_query() )
		$query->set( 'post_type', array( 'post', 'page', '6sm_company_profile' ) );
	return $query;
}

//trigger importing LinkedIn company profile when user submits it
add_action( 'wpcf7_submit', 'wpcf7_linkedin_submit', 10, 2 );

//import LinkedIn company profile
function wpcf7_linkedin_submit( $contactform, $result ) {
    $posted_data = $contactform->posted_data;
    
    //quit because the form isn't a company profile form if it doesn't have linkedin-url field
    if (!array_key_exists('linkedin-url', $posted_data)){
        return;
    }
    
    //get category id of Company Profile --- no need to set category anymore because it has custom post type Company Profile
    //$cat_id = get_cat_ID('Company Profile');
    
    foreach ( $posted_data as $key => $value ) {
        if (stripos($key, '_wp') !== false) continue;
		$content .= sprintf("<div><span>%s</span><span>:</span><span>%s</span></div>",$key, $value);
	}
    
    $postarr = array(
            'post_type' => '6sm_company_profile',
			'post_title' => $posted_data['company-name'],
			'post_content' => $content,
            //'post_category' => array($cat_id),
            );
	$post_id = wp_insert_post( $postarr );
    
    //add all fields of the form to the post, as custom field
    foreach ( $posted_data as $key => $value ) {
        if (stripos($key, '_wp') !== false) continue;
		add_post_meta($post_id, $key, $value, true);//add unique custom field
	}
}

//the following actions are for admin only, so quit if the current user isn't admin
if(!is_admin()) return;

add_filter('post_row_actions', 'import_linkedin_company_make_import_link_row',10,2);
add_action('admin_action_import_linkedin_company_import', 'import_linkedin_company_import');
/**
 * Add the link to action list for post_row_actions
 */
function import_linkedin_company_make_import_link_row($actions, $post) {
    //TODO: don't add the action if the post isn't a Company Profile
    //only admin can import company profile
	if (is_admin() && '6sm_company_profile' == $post->post_type) {
		$actions['Import'] = '<a href="' . admin_url( "admin.php". '?action=import_linkedin_company_import') . '&post=' . $post->ID . '">Import LinkedIn</a>';
	}
	return $actions;
}

define('API_KEY',      '75lr3wm72oaodb'                                          );
define('API_SECRET',   'HsrgoqpCMG1YQTHh'                                       );
//define('REDIRECT_URI', 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['SCRIPT_NAME']);
define('SCOPE',        'r_fullprofile'                        );
/**
 * Import company profile from LinkedIn.
 * Steps:
 *  1. get API KEY and PRIVATE KEY
 *  2. get authentication code if it's not in session
 */
function import_linkedin_company_import(){
    session_name('linkedin');
    session_start();
    
    if (isset($_GET['error'])) {
        // LinkedIn returned an error
        print $_GET['error'] . ': ' . $_GET['error_description'];
        exit;
    } elseif (isset($_GET['code'])) {
        // User authorized your application
        if ($_SESSION['state'] == $_GET['state']) {
            // Get token so you can make API calls
            getAccessToken();
        } else {
            // CSRF attack? Or did you mix up your states?
            print 'CSRF attack' . $_SESSION['state'];
            exit;
        }
    } else { 
        if (empty($_SESSION['access_token'])) {
            $REDIRECT_URI = admin_url('admin.php?action=import_linkedin_company_import&post=' . $_REQUEST['post']);
            // Start authorization process
            getAuthorizationCode($REDIRECT_URI);
            exit;
        }
    }
    
    //get the URL to the company profile on LinkedIn
    $linkedin_url = get_post_meta($_REQUEST['post'], 'linkedin-url', true);
    
    if (empty($linkedin_url)) {
        print 'not a company profile. pls go back.';
        exit;
    }
    //the last part of the url is either the company Id or company universal name
    $pieces = explode("/", $linkedin_url);
    $id = end($pieces);
    //LinkedIn API URL format
    //http://api.linkedin.com/v1/companies/162479 
    //http://api.linkedin.com/v1/companies/universal-name=linkedin
    $api = '/v1/companies/';
    if (is_int()){
        $api .= $id;
    } else {
        $api .= 'universal-name=' . $id;
    }
    $api .= ':(id,name,description,company-type,website-url,industries,logo-url,square-logo-url,employee-count-range,founded-year,locations)';
    $user = fetch('GET', $api);
    
    //update main content of the post
    $post_id = $_REQUEST['post'];
    $post = array('ID'=>$post_id, 'post_content'=>$user->description);
    wp_update_post($post);
    
    //delete old meta
    delete_post_meta($post_id, 'linkedin-response');
    delete_post_meta($post_id, 'linkedin-id');
    delete_post_meta($post_id, 'company-type');
    delete_post_meta($post_id, 'website-url');
    delete_post_meta($post_id, 'industries');
    delete_post_meta($post_id, 'logo-url');
    delete_post_meta($post_id, 'square-logo-url');
    delete_post_meta($post_id, 'employee-count-range');
    delete_post_meta($post_id, 'founded-year');
    delete_post_meta($post_id, 'locations');
    
    //add new meta values
    add_post_meta($post_id, 'linkedin-response', print_r($user, true),true);
    add_post_meta($post_id, 'linkedin-id', $user->id,true);
    add_post_meta($post_id, 'company-type', $user->companyType->name,true);
    add_post_meta($post_id, 'website-url', $user->websiteUrl, true);
    foreach ($user->industries->values as $v){
        add_post_meta($post_id, 'industries', $v->name,false);
    }
    add_post_meta($post_id, 'logo-url', $user->logoUrl,true);
    add_post_meta($post_id, 'square-logo-url', $user->squareLogoUrl,true);
    add_post_meta($post_id, 'employee-count-range', $user->employeeCountRange->name,true);
    add_post_meta($post_id, 'founded-year', $user->foundedYear,true);
    $address = $user->locations->values[0]->address;
    add_post_meta($post_id, 'locations', $address->city . ' ' . $address->state,true);
    
    wp_redirect(admin_url('post.php?action=edit&post=' . $post_id . '&name=' . $user->name . '&error=' . $_GET['error']));
    exit;
}

/**
 * Get authorization code from LinkedIn
 */
function getAuthorizationCode($REDIRECT_URI) {
	$params = array('response_type' => 'code',
					'client_id' => API_KEY,
					'scope' => SCOPE,
					'state' => uniqid('', true), // unique long string
					'redirect_uri' => $REDIRECT_URI,
			  );

	// Authentication request
	$url = 'https://www.linkedin.com/uas/oauth2/authorization?' . http_build_query($params);
	
	// Needed to identify request when it returns to us
	$_SESSION['state'] = $params['state'];

	// Redirect user to authenticate
	header("Location: $url");
	exit;
}

/**
 * get access token
 */
function getAccessToken() {
	$params = array('grant_type' => 'authorization_code',
					'client_id' => API_KEY,
					'client_secret' => API_SECRET,
					'code' => $_GET['code'],
					'redirect_uri' => REDIRECT_URI,//redirect URI is actually useless here because the response is a JSON response and there is no redirect
			  );
	
	// Access Token request
	$url = 'https://www.linkedin.com/uas/oauth2/accessToken?' . http_build_query($params);
	
	// Tell streams to make a POST request
	$context = stream_context_create(
					array('http' => 
						array('method' => 'POST',
	                    )
	                )
	            );

	// Retrieve access token information
	$response = file_get_contents($url, false, $context);

	// Native PHP object, please
	$token = json_decode($response);

	// Store access token and expiration time
	$_SESSION['access_token'] = $token->access_token; // guard this! 
	$_SESSION['expires_in']   = $token->expires_in; // relative time (in seconds)
	$_SESSION['expires_at']   = time() + $_SESSION['expires_in']; // absolute time
	
	return true;
}

/**
 * fetch profile from LinkedIn via API
 */
function fetch($method, $resource, $body = '') {
	$params = array('oauth2_access_token' => $_SESSION['access_token'],
					'format' => 'json',
			  );
	
	// Need to use HTTPS
	$url = 'https://api.linkedin.com' . $resource . '?' . http_build_query($params);
	// Tell streams to make a (GET, POST, PUT, or DELETE) request
	$context = stream_context_create(
					array('http' => 
						array('method' => $method,
	                    )
	                )
	            );

	// Hocus Pocus
	$response = file_get_contents($url, false, $context);

	// Native PHP object, please
	return json_decode($response);
}


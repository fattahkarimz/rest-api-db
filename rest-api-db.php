<?php
/*
Plugin Name: Rest API DB
Description: A plugin to create a secure REST API for a rest_table.
Version: 1.0
Author: Fattahkarim
*/

// Create a custom database table
register_activation_hook(__FILE__, 'custom_database_api_install');
function custom_database_api_install() {
    global $wpdb;
    $table_rest = $wpdb->prefix . 'rest_table';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_rest (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        fullname VARCHAR(255) NOT NULL,
        passport VARCHAR(255) NOT NULL,
        educor_id VARCHAR(255) NOT NULL,   
        com_date datetime NOT NULL,     
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Generate a random token
function generate_random_token() {
    $token_length = 32;
    return bin2hex(random_bytes($token_length));
}

// Create REST API endpoints with token authentication
add_action('rest_api_init', 'custom_database_api_register_routes');
function custom_database_api_register_routes() {
    register_rest_route('custom-database-api/v1', '/data/', array(
        'methods'  => 'POST',
        'callback' => 'custom_database_api_post_data',
        'permission_callback' => 'custom_database_api_check_token',
    ));

    register_rest_route('custom-database-api/v1', '/refresh-data/', array(
        'methods'  => 'GET',
        'callback' => 'custom_database_api_refresh_data',
        'permission_callback' => 'custom_database_api_check_token',
    ));
}

// Check if the token is valid
function custom_database_api_check_token() {
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

    // Check if the token is valid
    $valid_token = get_option('custom_database_api_token');
    return !empty($valid_token) && $token === $valid_token;
}

// Define the callback function for the endpoint to post data
function custom_database_api_post_data($data) {
    global $wpdb;
    $table_rest = $wpdb->prefix . 'rest_table';

    // Retrieve data from the rest_table using the passport parameter
    $passport = isset($data['passport']) ? sanitize_text_field($data['passport']) : '';

    $data_to_post = $wpdb->get_row(
        $wpdb->prepare("SELECT passport, educor_id, fullname, com_date FROM $table_rest WHERE passport = %s", $passport)
    );

    if (!$data_to_post) {
        return new WP_Error('no_data_to_post', 'No data available to post.', array('status' => 404));
    }

    // Insert data into the rest_table
    $wpdb->insert(
        $table_rest,
        array(
            'passport' => $data_to_post->passport,
            'educor_id' => $data_to_post->educor_id,
            'fullname' => $data_to_post->fullname,
            'com_date' => $data_to_post->com_date,
        ),
        array('%s', '%s', '%s', '%s')
    );

    return rest_ensure_response(array('message' => 'Data posted successfully.'));
}

// // Define the callback function for refreshing data
function custom_database_api_refresh_data() {
    global $wpdb;
    $table_civil = $wpdb->prefix . 'civilized_table';
    $table_rest = $wpdb->prefix . 'rest_table';

    // Check if the nonce is valid
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';

    if (!wp_verify_nonce($nonce, 'refresh_data_nonce')) {
        return rest_ensure_response(array('error' => 'Invalid nonce.'));
    }

    // Log the refresh process
    error_log('Refreshing data process started...');

    // Pull data from civilized_table to rest_table for the past 3 months
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO $table_rest (passport, educor_id, fullname, com_date) 
            SELECT passport, educor_id, fullname, com_date 
            FROM $table_civil 
            WHERE com_date >= %s",
            date('Y-m-d', strtotime('-3 months'))
        )
    );

    // Log the SQL query
    error_log('SQL Query: ' . $wpdb->last_query);

    // Log the completion of the refresh process
    error_log('Data refreshed successfully.');

    return rest_ensure_response(array('message' => 'Data refreshed successfully.'));
}

// Function to insert data into rest_table
function custom_database_api_insert_data() {
    global $wpdb;

    $table_civil = $wpdb->prefix . 'civilized_table';
    $table_rest = $wpdb->prefix . 'rest_table';

    // Get data from civilized_table
    $data_to_insert = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT passport, educor_id, fullname, com_date 
            FROM $table_civil 
            WHERE com_date >= %s",
            date('Y-m-d', strtotime('-3 months'))
        ),
        ARRAY_A
    );

    if (empty($data_to_insert)) {
        return;
    }

    foreach ($data_to_insert as $data) {
        // Check if the record already exists in rest_table
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_rest WHERE passport = %s", $data['passport']));

        if ($exists == 0) {
            // Insert the data if it does not exist
            $wpdb->insert(
                $table_rest,
                array(
                    'passport' => $data['passport'],
                    'educor_id' => $data['educor_id'],
                    'fullname' => $data['fullname'],
                    'com_date' => $data['com_date']
                ),
                array('%s', '%s', '%s', '%s')
            );
        }
    }

    // Log the completion of the insert process
    error_log('Data insertion process completed.');
}



// Initialize the plugin and set up the token on activation
function custom_database_api_init() {
    // Set up the token on plugin activation
    $token = get_option('custom_database_api_token');
    if (empty($token)) {
        $token = generate_random_token();
        update_option('custom_database_api_token', $token);
    }
}

add_action('admin_menu', 'custom_database_api_menu');
function custom_database_api_menu() {
    add_menu_page(
        'Rest API Setting',
        'Rest API Setting',
        'manage_options',
        'custom-database-api-setting',
        'custom_database_api_setting_page'
    );
}

function custom_database_api_setting_page() {
    ?>
    <div class="wrap">
        <h2>Rest API Setting</h2>
        <h2 class="nav-tab-wrapper">
            <a href="?page=custom-database-api-setting&tab=rest-table" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'rest-table' ? 'nav-tab-active' : ''; ?>">Rest Table</a>
            <a href="?page=custom-database-api-setting&tab=token-generator" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'token-generator' ? 'nav-tab-active' : ''; ?>">Token Generator</a>
        </h2>
        <?php
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'rest-table';

        if ($active_tab === 'rest-table') {
            // Display REST table and API endpoint information
            global $wpdb;
            $table_rest = $wpdb->prefix . 'rest_table';

            // Add Pagination Parameters
            $per_page = 10;
            $current_page = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_rest");
            $total_pages = ceil($total_items / $per_page);
            $offset = ($current_page - 1) * $per_page;

            // Retrieve Paginated Data
            $rest_data = $wpdb->get_results("SELECT * FROM $table_rest ORDER BY com_date DESC LIMIT $per_page OFFSET $offset", ARRAY_A);

            ?>
            <div id="rest-table" class="tab-content">
                <h3>Endpoint URL for External Access</h3>
                <p>Use the following URL to access the REST API endpoint:</p>
                <code><?php echo home_url('/wp-json/custom-database-api/v1/data/'); ?></code>
                <hr>
                <h3>Rest Table Data</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fullname</th>
                            <th>Passport</th>
                            <th>Educor ID</th>
                            <th>Issued Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($rest_data as $row) {
                            echo '<tr>';
                            echo '<td>' . esc_html($row['id']) . '</td>';
                            echo '<td>' . esc_html($row['fullname']) . '</td>';
                            echo '<td>' . esc_html($row['passport']) . '</td>';
                            echo '<td>' . esc_html($row['educor_id']) . '</td>';
                            echo '<td>' . esc_html($row['com_date']) . '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <!-- Pagination Links -->
                <?php
                if ($total_pages > 1) {
                    $page_links = paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page,
                        'before_page_number' => '<span class="screen-reader-text">' . __('Page') . ' </span>',
                    ));

                    if ($page_links) {
                        echo '<div class="tablenav bottom">';
                        echo '<div class="tablenav-pages" style="margin: 1em 0">';
                        echo $page_links;
                        echo '</div>';
                        echo '</div>';
                    }
                }
                ?>

                <p>
                    <form method="post" action="">
                        <input type="hidden" name="refresh_data_nonce" value="<?php echo wp_create_nonce('refresh_data_nonce'); ?>">
                        <button type="submit" name="refresh_data" class="button-primary">Refresh Data</button>
                    </form>
                </p>

                <?php
                // Check if the refresh button is clicked
                if (isset($_POST['refresh_data']) && wp_verify_nonce($_POST['refresh_data_nonce'], 'refresh_data_nonce')) {
                    custom_database_api_insert_data();
                    echo '<div class="updated"><p>Data refreshed successfully!</p></div>';
                }
                ?>
            </div>
            <?php
        } elseif ($active_tab === 'token-generator') {
            // Display Token Generator UI
            ?>
            <div id="token-generator" class="tab-content">
                <form method="post" action="">
                    <p>
                        <label for="token">Generated Token:</label>
                        <input type="text" id="token" name="token" value="<?php echo get_option('custom_database_api_token'); ?>" readonly>
                    </p>
                    <p>
                        <input type="submit" name="generate_token" class="button-primary" value="Generate New Token">
                    </p>
                </form>
            </div>
            <?php
            // Handle token generation
            if (isset($_POST['generate_token'])) {
                $token = generate_random_token();
                update_option('custom_database_api_token', $token);
                echo '<div class="updated"><p>Token generated successfully!</p></div>';
            }
        }
        ?>
        <div style="position: fixed; bottom: 0; right: 0; margin: 10px;">
            Plugin created by: Fattahkarimz
        </div>
    </div>
    <?php
}

register_activation_hook(__FILE__, 'custom_database_api_init');

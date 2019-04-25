<?php
/**
 * UniFi API browser
 *
 * This tool is for browsing data that is exposed through Ubiquiti's UniFi Controller API,
 * and is developed with PHP, JavaScript and the Bootstrap CSS framework.
 *
 * Please keep the following in mind:
 * - not all data collections/API endpoints are supported (yet), see the list of
 *   the currently supported data collections/API endpoints in the README.md file
 * - this tool currently supports versions 4.x and 5.x of the UniFi Controller software
 * ------------------------------------------------------------------------------------
 *
 * Copyright (c) 2017, Art of WiFi
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.md
 *
 */
define('API_BROWSER_VERSION', '1.0.36');
define('API_CLASS_VERSION', get_client_version());

/**
 * check whether the required PHP curl module is available
 * - if yes, collect cURL version details for the info modal
 * - if not, stop and display an error message
 */
if (function_exists('curl_version')) {
    $curl_info       = curl_version();
    $curl_version    = $curl_info['version'];
    $openssl_version = $curl_info['ssl_version'];
} else {
    exit('The <b>PHP curl</b> module is not installed! Please correct this before proceeding!<br>');
    $curl_version    = 'unavailable';
    $openssl_version = 'unavailable';
}

/**
 * in order to use the PHP $_SESSION array for temporary storage of variables, session_start() is required
 */
session_start([
  'cookie_lifetime' => 604800,
]);

/**
 * check whether user has requested to clear (force expiry) the PHP session
 * - this feature can be useful when login errors occur, mostly after upgrades or credential changes
 */
if (isset($_GET['reset_session']) && $_GET['reset_session'] == true) {
    $_SESSION = [];
    session_unset();
    session_destroy();
    session_start([
      'cookie_lifetime' => 604800,
    ]);
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

/**
 * starting timing of the session here
 */
$time_start = microtime(true);

/**
 * declare variables which are required later on together with their default values
 */
$show_login         = false;
$controller_id      = '';
$action             = '';
$site_id            = '';
$site_name          = '';
$selection          = '';
$data               = '';
$objects_count      = '';
$alert_message      = '';
$output_format      = 'json';
$controlleruser     = '';
$controllerpassword = '';
$controllerurl      = '';
$controllername     = 'Controller';
$cookietimeout      = '604800';
$theme              = 'bootstrap';
$debug              = false;

/**
 * load the optional configuration file if readable
 * - allows override of several of the previously declared variables
 */
if (is_file('config.php') && is_readable('config.php')) {
    include('config.php');
}

/**
 * load the UniFi API client and Kint classes using composer autoloader
 */
require('vendor/autoload.php');

/**
 * set relevant Kint options
 * more info on Kint usage: http://kint-php.github.io/kint/
 */
Kint::$display_called_from = false;

/**
 * determine whether we have reached the cookie timeout, if so, refresh the PHP session
 * else, update last activity time stamp
 */
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $cookietimeout)) {
    /**
     * last activity was longer than $cookietimeout seconds ago
     */
    session_unset();
    session_destroy();
    if ($debug) {
        error_log('UniFi API browser INFO: session cookie timed out');
    }
}

$_SESSION['last_activity'] = time();

/**
 * process the GET variables and store them in the $_SESSION array
 * if a GET variable is not set, get the values from the $_SESSION array (if available)
 *
 * process in this order:
 * - controller_id
 * only process this after controller_id is set:
 * - site_id
 * only process these after site_id is set:
 * - action
 * - output_format
 */
if (isset($_GET['controller_id'])) {
    /**
     * user has requested a controller switch
     */
    if (!isset($controllers)) {
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?reset_session=true");
        exit;
    }
    $controller                = $controllers[$_GET['controller_id']];
    $controller_id             = $_GET['controller_id'];
    $_SESSION['controller']    = $controller;
    $_SESSION['controller_id'] = $_GET['controller_id'];

    /**
     * clear the variables from the $_SESSION array that are associated with the previous controller session
     */
    unset($_SESSION['site_id']);
    unset($_SESSION['site_name']);
    unset($_SESSION['sites']);
    unset($_SESSION['action']);
    unset($_SESSION['detected_controller_version']);
    unset($_SESSION['unificookie']);
} else {
    if (isset($_SESSION['controller']) && isset($controllers)) {
        $controller    = $_SESSION['controller'];
        $controller_id = $_SESSION['controller_id'];
    } else {
        if (!isset($controllers)) {
            /**
             * pre-load $controller array with $_SESSION['controllers'] if present
             * then load configured single site credentials
             */
            $controller = [];
            if (isset($_SESSION['controller'])) {
                $controller = $_SESSION['controller'];
            }
            if (!isset($controller['user']) || !isset($controller['password']) || !isset($controller['url'])) {
                $_SESSION['controller'] = [
                    'user'     => $controlleruser,
                    'password' => $controllerpassword,
                    'url'      => $controllerurl,
                    'name'     => $controllername
                ];
                $controller = $_SESSION['controller'];
            }
        }
    }

    if (isset($_GET['site_id'])) {
        $site_id               = $_GET['site_id'];
        $_SESSION['site_id']   = $site_id;
        $site_name             = $_GET['site_name'];
        $_SESSION['site_name'] = $site_name;
    } else {
        if (isset($_SESSION['site_id'])) {
            $site_id   = $_SESSION['site_id'];
            $site_name = $_SESSION['site_name'];
        }
    }
}

/**
 * load login form data, if present, and save to credential variables
 */
if (isset($_POST['controller_user']) && !empty($_POST['controller_user'])) {
    $controller['user'] = $_POST['controller_user'];
}

if (isset($_POST['controller_password']) && !empty($_POST['controller_password'])) {
    $controller['password'] = $_POST['controller_password'];
}

if (isset($_POST['controller_url']) && !empty($_POST['controller_url'])) {
    $controller['url'] = $_POST['controller_url'];
}

if (isset($controller)) {
    $_SESSION['controller'] = $controller;
}

/**
 * get requested theme or use the theme stored in the $_SESSION array
 */
if (isset($_GET['theme'])) {
    $theme             = $_GET['theme'];
    $_SESSION['theme'] = $theme;
    $theme_changed     = true;
} else {
    if (isset($_SESSION['theme'])) {
        $theme = $_SESSION['theme'];
    }

    $theme_changed = false;
}

/**
 * get requested output_format or use the output_format stored in the $_SESSION array
 */
if (isset($_GET['output_format'])) {
    $output_format             = $_GET['output_format'];
    $_SESSION['output_format'] = $output_format;
} else {
    if (isset($_SESSION['output_format'])) {
        $output_format = $_SESSION['output_format'];
    }
}

/**
 * get requested action or use the action stored in the $_SESSION array
 */
if (isset($_GET['action'])) {
    $action             = $_GET['action'];
    $_SESSION['action'] = $action;
} else {
    if (isset($_SESSION['action'])) {
        $action = $_SESSION['action'];
    }
}

/**
 * display info message when no controller, site or data collection is selected
 * placed here so they can be overwritten by more "severe" error messages later on
 */
// if ($action === '') {
//     $alert_message = '<div class="alert alert-info" role="alert">Please select a data collection/API endpoint from the dropdown menus ' .
//                      '<i class="fa fa-arrow-circle-up"></i></div>';
// }

// if ($site_id === '') {
//     $alert_message = '<div class="alert alert-info" role="alert">Please select a site from the Sites dropdown menu ' .
//                      '<i class="fa fa-arrow-circle-up"></i></div>';
// }

if (!isset($controller['name']) && isset($controllers)) {
    $alert_message = '<div class="alert alert-info" role="alert">Please select a controller from the Controllers dropdown menu ' .
                     '<i class="fa fa-arrow-circle-up"></i></div>';
} else {
    if (!isset($_SESSION['unificookie']) && (empty($controller['user']) || empty($controller['password']) || empty($controller['url']))) {
        $show_login    = true;
    }
}


/**
 * do this when a controller has been selected and was stored in the $_SESSION array and login isn't needed
 */
if (isset($_SESSION['controller']) && $show_login !== true) {
    /**
     * create a new instance of the API client class and log in to the UniFi controller
     * - if an error occurs during the login process, an alert is displayed on the page
     */
    $unifidata      = new UniFi_API\Client(trim($controller['user']), $controller['password'], rtrim(trim($controller['url']), '/'), $site_id);
    $set_debug_mode = $unifidata->set_debug($debug);
    $loginresults   = $unifidata->login();

    if ($loginresults === 400) {
        $alert_message = '<div class="alert alert-danger" role="alert">HTTP response status: 400' .
                         '<br>This is probably caused by a UniFi controller login failure. Please check your credentials and ' .
                         '<a href="?reset_session=true">try again</a>.</div>';

        /**
         * to prevent unwanted errors we assign empty values to the following variables
         */
        $sites                       = [];
        $detected_controller_version = 'undetected';
    } else {
        /**
         * remember authentication cookie to the controller.
         */
        $_SESSION['unificookie'] = $unifidata->get_cookie();

        /**
         * get the list of sites managed by the UniFi controller (if not already stored in the $_SESSION array)
         */
        if (!isset($_SESSION['sites']) || empty($_SESSION['sites'])) {
            $sites = $unifidata->list_sites();
            if (is_array($sites)) {
                $_SESSION['sites'] = $sites;
            } else {
                $sites = [];

                $alert_message = '<div class="alert alert-danger" role="alert">No sites available' .
                                 '<br>This is probably caused by incorrect access rights in the UniFi controller, or the controller is not ' .
                                 'accepting connections. Please check your credentials and/or your server error logs and ' .
                                 '<a href="?reset_session=true">try again</a>.</div>';
            }

        } else {
            $sites = $_SESSION['sites'];
        }

        /**
         * get the version of the UniFi controller (if not already stored in the $_SESSION array or when 'undetected')
         */
        if (!isset($_SESSION['detected_controller_version']) || $_SESSION['detected_controller_version'] === 'undetected') {
            $site_info = $unifidata->stat_sysinfo();

            if (isset($site_info[0]->version)) {
                $detected_controller_version             = $site_info[0]->version;
                $_SESSION['detected_controller_version'] = $detected_controller_version;
            } else {
                $detected_controller_version             = 'undetected';
                $_SESSION['detected_controller_version'] = 'undetected';
            }

        } else {
            $detected_controller_version = $_SESSION['detected_controller_version'];
        }
    }
}

/**
 * execute timing of controller login
 */
$time_1           = microtime(true);
$time_after_login = $time_1 - $time_start;

if (isset($unifidata)) {
    /**
     * array containing attributes to fetch for the gateway stats, overriding
     * the default attributes
     */
    $gateway_stats_attribs = [
        'time',
        'mem',
        'cpu',
        'loadavg_5',
        'lan-rx_errors',
        'lan-tx_errors',
        'lan-rx_bytes',
        'lan-tx_bytes',
        'lan-rx_packets',
        'lan-tx_packets',
        'lan-rx_dropped',
        'lan-tx_dropped'
    ];

    /**
     * select the required call to the UniFi Controller API based on the selected action
     */
    $selection = 'daily site stats';
    $data      = $unifidata->stat_daily_site();
}

/**
 * count the number of objects collected from the UniFi controller
 */
if ($action != '' && !empty($data)) {
    $objects_count = count($data);
}

/**
 * execute timing of data collection from UniFi controller
 */
$time_2          = microtime(true);
$time_after_load = $time_2 - $time_start;

/**
 * calculate all the timings/percentages
 */
$time_end    = microtime(true);
$time_total  = $time_end - $time_start;
$login_perc  = ($time_after_login / $time_total) * 100;
$load_perc   = (($time_after_load - $time_after_login) / $time_total) * 100;
$remain_perc = 100 - $login_perc - $load_perc;

/**
 * shared functions
 */

/**
 * function to print the output
 * switch depending on the selected $output_format
 */
function print_output($output_format, $data)
{
    switch ($output_format) {
        case 'json':
            echo json_encode($data, JSON_PRETTY_PRINT);
            break;
        case 'json_color':
            echo json_encode($data);
            break;
        case 'php_array':
            print_r($data);
            break;
        case 'php_array_kint':
            +d($data);
            break;
        case 'php_var_dump':
            var_dump($data);
            break;
        case 'php_var_export':
            var_export($data);
            break;
        default:
            echo json_encode($data, JSON_PRETTY_PRINT);
            break;
    }
}

/**
 * function to sort the sites collection alpabetically by description
 */
function sites_sort($site_a, $site_b)
{
    return strcmp($site_a->desc, $site_b->desc);
}

/**
 * function which returns the version of the included API client class by
 * extracting it from the composer.lock file
 */
function get_client_version()
{
    if (is_readable('composer.lock')) {
        $composer_lock = file_get_contents('composer.lock');
        $json_decoded = json_decode($composer_lock, true);

        if (isset($json_decoded['packages'])) {
            foreach ($json_decoded['packages'] as $package) {
                if ($package['name'] === 'art-of-wifi/unifi-api-client') {
                    return substr($package['version'], 1);
                }
            }
        }
    }

    return 'unknown';
}
?>
<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta charset="utf-8">
        <title>UniFi Stats</title>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <!-- latest compiled and minified versions of Bootstrap, Font-awesome and Highlight.js CSS, loaded from CDN -->
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">

        <!-- load the default Bootstrap CSS file from CDN -->
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">

        <!-- placeholder to dynamically load the appropriate Bootswatch CSS file from CDN -->
        <link rel="stylesheet" href="" id="bootswatch_theme_stylesheet">

        <!-- load the jsonview CSS file from CDN -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-jsonview/1.2.3/jquery.jsonview.min.css" integrity="sha256-OhImf+9TMPW5iYXKNT4eRNntf3fCtVYe5jZqo/mrSQA=" crossorigin="anonymous">

        <!-- define favicon  -->
        <link rel="shortcut icon" href="favicon.png" type="image/x-icon">
        <link rel="icon" sizes="16x16" href="favicon.png" type="image/x-icon" >

        <!-- custom CSS styling -->
        <style>
            body {
                padding-top: 70px;
            }

            .scrollable-menu {
                height: auto;
                max-height: 80vh;
                overflow-x: hidden;
                overflow-y: auto;
            }

            #output_panel_loading {
                color: rgba(0,0,0,.4);
            }

            #output {
                display: none;
                position:relative;
            }

            .back-to-top {
                cursor: pointer;
                position: fixed;
                bottom: 20px;
                right: 20px;
                display:none;
            }

            #copy_to_clipboard_button {
                 position: absolute;
                 top: 0;
                 right: 0;
            }

            #toggle_buttons {
                 display: none;
            }
        </style>
        <!-- /custom CSS styling -->
    </head>
    <body>
        <!-- top navbar -->
        <nav id="navbar" class="navbar navbar-default navbar-fixed-top">
            <div class="container-fluid">
                <div class="navbar-header">
                    <button class="navbar-toggle collapsed" type="button" data-toggle="collapse" data-target="#navbar-main">
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </button>
                    <a class="navbar-brand" href="index.php">
                      <svg ng-switch-when="brand-unifi" ng-class="{&quot;ubntIcon&quot;:true,&quot;ubntIcon--auto&quot;:true}" width="119px" height="23px" viewBox="0 0 119 23" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" class="ubntIcon ubntIcon--auto"><g transform="translate(-120.000000, -11.000000)" fill="currentColor"><path d="M166.74,19.32 C166.913334,19.32 167.041666,19.3416665 167.125,19.385 C167.208334,19.4283336 167.303333,19.516666 167.41,19.65 L175.71,30.45 C175.69,30.2766658 175.676667,30.1083342 175.67,29.945 C175.663333,29.7816659 175.66,29.6233341 175.66,29.47 L175.66,19.32 L177.36,19.32 L177.36,33.65 L176.38,33.65 C176.226666,33.65 176.098334,33.6233336 175.995,33.57 C175.891666,33.5166664 175.790001,33.4266673 175.69,33.3 L167.4,22.51 C167.413333,22.6766675 167.423333,22.8399992 167.43,23 C167.436667,23.1600008 167.44,23.306666 167.44,23.44 L167.44,33.65 L165.74,33.65 L165.74,19.32 L166.74,19.32 Z M184.09,23.36 C184.69667,23.36 185.256664,23.4616657 185.77,23.665 C186.283336,23.8683344 186.726665,24.1616648 187.1,24.545 C187.473335,24.9283353 187.764999,25.4016639 187.975,25.965 C188.185001,26.5283362 188.29,27.1699964 188.29,27.89 C188.29,28.1700014 188.26,28.3566662 188.2,28.45 C188.14,28.5433338 188.026668,28.59 187.86,28.59 L181.12,28.59 C181.133333,29.2300032 181.219999,29.7866643 181.38,30.26 C181.540001,30.7333357 181.759999,31.1283318 182.04,31.445 C182.320001,31.7616683 182.653331,31.9983326 183.04,32.155 C183.426669,32.3116675 183.859998,32.39 184.34,32.39 C184.786669,32.39 185.171665,32.3383339 185.495,32.235 C185.818335,32.1316662 186.096666,32.0200006 186.33,31.9 C186.563335,31.7799994 186.758333,31.6683339 186.915,31.565 C187.071667,31.4616662 187.206666,31.41 187.32,31.41 C187.466667,31.41 187.58,31.4666661 187.66,31.58 L188.16,32.23 C187.939999,32.496668 187.676668,32.7283324 187.37,32.925 C187.063332,33.1216677 186.735002,33.2833327 186.385,33.41 C186.034998,33.5366673 185.673335,33.6316664 185.3,33.695 C184.926665,33.7583337 184.556669,33.79 184.19,33.79 C183.489997,33.79 182.845003,33.6716679 182.255,33.435 C181.664997,33.1983322 181.155002,32.851669 180.725,32.395 C180.294998,31.9383311 179.960001,31.3733367 179.72,30.7 C179.479999,30.0266633 179.36,29.2533377 179.36,28.38 C179.36,27.6733298 179.468332,27.0133364 179.685,26.4 C179.901668,25.7866636 180.213331,25.2550023 180.62,24.805 C181.026669,24.3549978 181.52333,24.001668 182.11,23.745 C182.69667,23.4883321 183.356663,23.36 184.09,23.36 Z M184.13,24.67 C183.269996,24.67 182.593336,24.9183309 182.1,25.415 C181.606664,25.9116692 181.300001,26.5999956 181.18,27.48 L186.69,27.48 C186.69,27.0666646 186.633334,26.6883351 186.52,26.345 C186.406666,26.001665 186.240001,25.7050013 186.02,25.455 C185.799999,25.2049988 185.531668,25.0116674 185.215,24.875 C184.898332,24.7383327 184.536669,24.67 184.13,24.67 Z M193.13,33.81 C192.329996,33.81 191.715002,33.5866689 191.285,33.14 C190.854998,32.6933311 190.64,32.0500042 190.64,31.21 L190.64,25.01 L189.42,25.01 C189.313333,25.01 189.223334,24.9783337 189.15,24.915 C189.076666,24.8516664 189.04,24.753334 189.04,24.62 L189.04,23.91 L190.7,23.7 L191.11,20.57 C191.123333,20.4699995 191.166666,20.3883337 191.24,20.325 C191.313334,20.2616664 191.406666,20.23 191.52,20.23 L192.42,20.23 L192.42,23.72 L195.32,23.72 L195.32,25.01 L192.42,25.01 L192.42,31.09 C192.42,31.5166688 192.523332,31.8333323 192.73,32.04 C192.936668,32.2466677 193.203332,32.35 193.53,32.35 C193.716668,32.35 193.878333,32.3250003 194.015,32.275 C194.151667,32.2249998 194.27,32.1700003 194.37,32.11 C194.470001,32.0499997 194.555,31.9950003 194.625,31.945 C194.695,31.8949998 194.756666,31.87 194.81,31.87 C194.903334,31.87 194.986666,31.9266661 195.06,32.04 L195.58,32.89 C195.273332,33.1766681 194.903336,33.4016659 194.47,33.565 C194.036665,33.7283342 193.590002,33.81 193.13,33.81 Z M195.7,23.52 L197.1,23.52 C197.246667,23.52 197.366666,23.5566663 197.46,23.63 C197.553334,23.7033337 197.616667,23.7899995 197.65,23.89 L199.59,30.41 C199.643334,30.6500012 199.693333,30.8816656 199.74,31.105 C199.786667,31.3283345 199.826667,31.5533322 199.86,31.78 C199.913334,31.5533322 199.973333,31.3283345 200.04,31.105 C200.106667,30.8816656 200.176666,30.6500012 200.25,30.41 L202.39,23.85 C202.423334,23.7499995 202.481666,23.666667 202.565,23.6 C202.648334,23.533333 202.753333,23.5 202.88,23.5 L203.65,23.5 C203.783334,23.5 203.893333,23.533333 203.98,23.6 C204.066667,23.666667 204.126667,23.7499995 204.16,23.85 L206.25,30.41 C206.323334,30.6433345 206.388333,30.8733322 206.445,31.1 C206.501667,31.3266678 206.556666,31.5499989 206.61,31.77 C206.643334,31.5499989 206.686666,31.3200012 206.74,31.08 C206.793334,30.8399988 206.85,30.6166677 206.91,30.41 L208.89,23.89 C208.923334,23.7833328 208.986666,23.6950004 209.08,23.625 C209.173334,23.5549997 209.283333,23.52 209.41,23.52 L210.75,23.52 L207.47,33.65 L206.06,33.65 C205.886666,33.65 205.766667,33.5366678 205.7,33.31 L203.46,26.44 C203.406666,26.2866659 203.363334,26.1316675 203.33,25.975 C203.296667,25.8183326 203.263334,25.6633341 203.23,25.51 C203.196667,25.6633341 203.163334,25.8199992 203.13,25.98 C203.096667,26.1400008 203.053334,26.2966659 203,26.45 L200.73,33.31 C200.656666,33.5366678 200.520001,33.65 200.32,33.65 L198.98,33.65 L195.7,23.52 Z M215.94,23.36 C216.680004,23.36 217.34833,23.4833321 217.945,23.73 C218.54167,23.9766679 219.048331,24.3266644 219.465,24.78 C219.881669,25.2333356 220.201666,25.7816635 220.425,26.425 C220.648334,27.0683366 220.76,27.7866627 220.76,28.58 C220.76,29.380004 220.648334,30.0999968 220.425,30.74 C220.201666,31.3800032 219.881669,31.9266644 219.465,32.38 C219.048331,32.8333356 218.54167,33.1816655 217.945,33.425 C217.34833,33.6683346 216.680004,33.79 215.94,33.79 C215.199996,33.79 214.53167,33.6683346 213.935,33.425 C213.33833,33.1816655 212.830002,32.8333356 212.41,32.38 C211.989998,31.9266644 211.666668,31.3800032 211.44,30.74 C211.213332,30.0999968 211.1,29.380004 211.1,28.58 C211.1,27.7866627 211.213332,27.0683366 211.44,26.425 C211.666668,25.7816635 211.989998,25.2333356 212.41,24.78 C212.830002,24.3266644 213.33833,23.9766679 213.935,23.73 C214.53167,23.4833321 215.199996,23.36 215.94,23.36 Z M215.94,32.4 C216.940005,32.4 217.686664,32.0650034 218.18,31.395 C218.673336,30.7249967 218.92,29.790006 218.92,28.59 C218.92,27.3833273 218.673336,26.4433367 218.18,25.77 C217.686664,25.0966633 216.940005,24.76 215.94,24.76 C215.433331,24.76 214.993335,24.8466658 214.62,25.02 C214.246665,25.1933342 213.935001,25.4433317 213.685,25.77 C213.434999,26.0966683 213.248334,26.498331 213.125,26.975 C213.001666,27.4516691 212.94,27.989997 212.94,28.59 C212.94,29.190003 213.001666,29.7266643 213.125,30.2 C213.248334,30.6733357 213.434999,31.0716651 213.685,31.395 C213.935001,31.718335 214.246665,31.9666658 214.62,32.14 C214.993335,32.3133342 215.433331,32.4 215.94,32.4 Z M222.46,33.65 L222.46,23.52 L223.48,23.52 C223.673334,23.52 223.806666,23.5566663 223.88,23.63 C223.953334,23.7033337 224.003333,23.8299991 224.03,24.01 L224.15,25.59 C224.496668,24.8833298 224.924997,24.3316687 225.435,23.935 C225.945003,23.5383314 226.54333,23.34 227.23,23.34 C227.510001,23.34 227.763332,23.3716664 227.99,23.435 C228.216668,23.4983337 228.426666,23.5866661 228.62,23.7 L228.39,25.03 C228.343333,25.1966675 228.240001,25.28 228.08,25.28 C227.986666,25.28 227.843334,25.2483337 227.65,25.185 C227.456666,25.1216664 227.186668,25.09 226.84,25.09 C226.219997,25.09 225.701669,25.2699982 225.285,25.63 C224.868331,25.9900018 224.520001,26.5133299 224.24,27.2 L224.24,33.65 L222.46,33.65 Z M231.87,18.92 L231.87,27.59 L232.33,27.59 C232.463334,27.59 232.573333,27.5716669 232.66,27.535 C232.746667,27.4983332 232.843333,27.4233339 232.95,27.31 L236.15,23.88 C236.250001,23.7733328 236.35,23.686667 236.45,23.62 C236.550001,23.553333 236.683333,23.52 236.85,23.52 L238.47,23.52 L234.74,27.49 C234.646666,27.6033339 234.555,27.7033329 234.465,27.79 C234.375,27.8766671 234.273334,27.953333 234.16,28.02 C234.280001,28.1000004 234.388333,28.1916662 234.485,28.295 C234.581667,28.3983339 234.673333,28.516666 234.76,28.65 L238.72,33.65 L237.12,33.65 C236.973333,33.65 236.848334,33.621667 236.745,33.565 C236.641666,33.5083331 236.543334,33.4200006 236.45,33.3 L233.12,29.15 C233.02,29.0099993 232.920001,28.9183336 232.82,28.875 C232.72,28.8316665 232.570001,28.81 232.37,28.81 L231.87,28.81 L231.87,33.65 L230.08,33.65 L230.08,18.92 L231.87,18.92 Z M138.08445,21.8888148 C139.013906,21.8888148 139.683662,22.1206727 140.093717,22.5843896 C140.503771,23.0481066 140.599451,23.8527908 140.380755,24.9984434 L138.494505,33.6726707 L136.1982,33.6726707 L137.920428,25.8167668 C138.029776,25.0529987 138.002439,24.5210886 137.838418,24.2210363 C137.674396,23.920984 137.291678,23.7709584 136.690265,23.7709584 C136.252874,23.7709584 135.801815,23.9482623 135.337086,24.3028686 C134.872357,24.657475 134.557982,25.2166634 134.393961,25.9804314 L132.753743,33.6726707 L130.457439,33.6726707 L132.917765,22.2161441 L135.050048,22.2161441 L134.804015,23.5254614 C135.186733,23.0344675 135.678798,22.6389449 136.280211,22.3388926 C136.881624,22.0388403 137.483038,21.8888148 138.08445,21.8888148 Z M139.068581,16.4060485 C138.63119,17.0607072 138.289477,17.756282 138.043445,18.492773 C137.797412,19.229264 137.619722,19.8975618 137.510374,20.4976652 L135.460102,20.4976652 C135.56945,19.7884512 135.760809,18.95649 136.034178,18.001779 C136.307547,17.0470681 136.799613,16.1059962 137.510374,15.1785635 C138.330483,13.9783556 139.546977,12.9690906 141.159858,12.1507672 C142.772739,11.3324439 144.836679,10.9505593 147.35168,11.0051146 L146.941625,13.0509229 C144.754668,13.0509229 143.073445,13.3782522 141.897956,14.0329109 C140.722467,14.6875695 139.779342,15.4786158 139.068581,16.4060485 Z M148.663854,24.7529464 L154.076572,24.7529464 L153.666517,26.7169224 L148.253799,26.7169224 L146.777603,33.6726707 L144.317277,33.6726707 L147.597712,18.4518568 L155.470757,18.4518568 L154.978691,20.4976652 L149.565973,20.4976652 L148.663854,24.7529464 Z M142.677059,22.2161441 L144.973364,22.2161441 L142.595049,33.6726707 L140.216733,33.6726707 L142.677059,22.2161441 Z M142.923092,20.4976652 C142.977766,20.3340005 143.032439,20.211252 143.087114,20.1294197 C143.141788,20.0475873 143.196461,19.9248388 143.251136,19.7611742 C143.524505,19.4338448 143.852548,19.1474317 144.235266,18.9019347 C144.617984,18.6564377 145.137386,18.5064121 145.793473,18.4518568 L145.301408,20.4976652 L142.923092,20.4976652 Z M153.994561,33.6726707 L156.372876,22.2161441 L158.751192,22.2161441 L156.290865,33.6726707 L153.994561,33.6726707 Z M129.473308,18.4518568 L131.933634,18.4518568 L129.63733,29.1718924 C129.254612,30.9176484 128.571188,32.1587725 127.587058,32.8952635 C126.602927,33.6317545 125.372764,34 123.896568,34 C122.420372,34 121.340562,33.6317545 120.657139,32.8952635 C119.973715,32.1587725 119.823361,30.9176484 120.206079,29.1718924 L122.502383,18.4518568 L124.96271,18.4518568 L122.666405,29.3355571 C122.447709,30.317545 122.475047,31.026759 122.748416,31.4631977 C123.021785,31.8996364 123.541188,32.1178564 124.306623,32.1178564 C125.017384,32.1178564 125.605128,31.8996364 126.069857,31.4631977 C126.534585,31.026759 126.876296,30.317545 127.094993,29.3355571 L129.473308,18.4518568 Z M146.613582,14.5239049 L146.121516,16.5697132 C145.028038,16.6788226 144.153255,16.9379587 143.497168,17.3471204 C142.841081,17.756282 142.349016,18.2063598 142.020972,18.6973538 C141.802276,18.9701279 141.624587,19.2701802 141.487902,19.5975095 C141.351216,19.9248388 141.2282,20.2248911 141.118853,20.4976652 L139.068581,20.4976652 C139.177928,20.0066712 139.328282,19.5156772 139.519641,19.0246832 C139.710999,18.5336892 139.998037,18.0154181 140.380755,17.4698689 C140.927494,16.7061008 141.720265,16.037803 142.75907,15.4649767 C143.797875,14.8921504 145.082711,14.5784602 146.613582,14.5239049 Z M157.192985,18.4518568 L158.054099,18.8201023 C158.628175,19.0655993 158.915213,19.6247865 158.915213,20.4976652 L156.70092,20.4976652 L157.192985,18.4518568 Z"></path></g></svg>
                    </a>
                </div>
                <div id="navbar-main" class="collapse navbar-collapse">
                    <ul class="nav navbar-nav navbar-left">
                        <!-- controllers dropdown, only show when multiple controllers have been configured -->
                        <?php if (isset($controllers)) { ?>
                            <li id="site-menu" class="dropdown">
                                <a id="controller-menu" href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                                    <?php
                                    /**
                                     * here we display the UniFi controller name, if selected, else just label it
                                     */
                                    if (isset($controller['name'])) {
                                        echo $controller['name'];
                                    } else {
                                        echo 'Controllers';
                                    }
                                    ?>
                                    <span class="caret"></span>
                                </a>
                                <ul class="dropdown-menu scrollable-menu" id="controllerslist">
                                    <li class="dropdown-header">Select a controller</li>
                                    <li role="separator" class="divider"></li>
                                    <?php
                                    /**
                                     * here we loop through the configured UniFi controllers
                                     */
                                    foreach ($controllers as $key => $value) {
                                        echo '<li id="controller_' . $key . '"><a href="?controller_id=' . $key . '">' . $value['name'] . '</a></li>' . "\n";
                                    }
                                    ?>
                                 </ul>
                            </li>
                        <?php } ?>
                        <!-- /controllers dropdown -->
                        <!-- sites dropdown, only show when a controller has been selected -->
                        <?php if ($show_login === false && isset($controller['name'])) { ?>
                            <li id="site-menu" class="dropdown">
                                <a id="site-menu" href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">

                                    <?php
                                    /**
                                     * here we display the site name, if selected, else just label it
                                     */
                                    if (!empty($site_name)) {
                                        echo $site_name;
                                    } else {
                                        echo 'Sites';
                                    }
                                    ?>
                                    <span class="caret"></span>
                                </a>
                                <ul class="dropdown-menu scrollable-menu" id="siteslist">
                                    <li class="dropdown-header">Select a site</li>
                                    <li role="separator" class="divider"></li>
                                    <?php
                                    /**
                                     * here we loop through the available sites, after we've sorted the sites collection
                                     */
                                    usort($sites, "sites_sort");

                                    foreach ($sites as $site) {
                                        $link_row = '<li id="' . $site->name . '"><a href="?site_id=' .
                                                    urlencode($site->name) . '&site_name=' . urlencode($site->desc) .
                                                    '">' . $site->desc . '</a></li>' . "\n";

                                        echo $link_row;
                                    }
                                    ?>
                                 </ul>
                            </li>
                        <?php } ?>
                        <!-- /sites dropdown -->
                        <!-- data collection dropdowns, only show when a site_id is selected -->
                        
                    </ul>
                    <ul class="nav navbar-nav navbar-right" style="margin-right: 0;">
                        <li id="theme-menu" class="dropdown">
                            <a id="theme-menu" href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                                <i class="fa fa-bars fa-lg"></i>
                            </a>
                            <ul class="dropdown-menu scrollable-menu">
                                <li id="info" data-toggle="modal" data-target="#about_modal"><a href="#"><i class="fa fa-info-circle"></i> About UniFi API browser</a></li>
                                <li role="separator" class="divider"></li>
                                <li id="reset_session" data-toggle="tooltip" data-container="body" data-placement="left"
                                    data-original-title="In some cases this can fix login errors and/or an empty sites list">
                                    <a href="?reset_session=true"><i class="fa fa-sign-out"></i> Log out</a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div><!-- /.nav-collapse -->
            </div><!-- /.container-fluid -->
        </nav><!-- /top navbar -->
        <div class="container-fluid">
            <div id="alert_placeholder" style="display: none"></div>
            <!-- login_form, only to be displayed when we have no controller config -->
            <div id="login_form" style="display: none">
                <div class="col-xs-offset-1 col-xs-10 col-sm-offset-3 col-sm-6 col-md-offset-3 col-md-6 col-lg-offset-4 col-lg-4">
                    <div class="panel panel-default">
                        <div class="panel-body">
                            <svg style="display: block; margin: 0 auto;" ng-switch-when="brand-unifi" ng-class="{&quot;ubntIcon&quot;:true,&quot;ubntIcon--auto&quot;:true}" width="200px" height="50px" viewBox="0 0 119 23" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" class="ubntIcon ubntIcon--auto"><g transform="translate(-120.000000, -11.000000)" fill="currentColor"><path d="M166.74,19.32 C166.913334,19.32 167.041666,19.3416665 167.125,19.385 C167.208334,19.4283336 167.303333,19.516666 167.41,19.65 L175.71,30.45 C175.69,30.2766658 175.676667,30.1083342 175.67,29.945 C175.663333,29.7816659 175.66,29.6233341 175.66,29.47 L175.66,19.32 L177.36,19.32 L177.36,33.65 L176.38,33.65 C176.226666,33.65 176.098334,33.6233336 175.995,33.57 C175.891666,33.5166664 175.790001,33.4266673 175.69,33.3 L167.4,22.51 C167.413333,22.6766675 167.423333,22.8399992 167.43,23 C167.436667,23.1600008 167.44,23.306666 167.44,23.44 L167.44,33.65 L165.74,33.65 L165.74,19.32 L166.74,19.32 Z M184.09,23.36 C184.69667,23.36 185.256664,23.4616657 185.77,23.665 C186.283336,23.8683344 186.726665,24.1616648 187.1,24.545 C187.473335,24.9283353 187.764999,25.4016639 187.975,25.965 C188.185001,26.5283362 188.29,27.1699964 188.29,27.89 C188.29,28.1700014 188.26,28.3566662 188.2,28.45 C188.14,28.5433338 188.026668,28.59 187.86,28.59 L181.12,28.59 C181.133333,29.2300032 181.219999,29.7866643 181.38,30.26 C181.540001,30.7333357 181.759999,31.1283318 182.04,31.445 C182.320001,31.7616683 182.653331,31.9983326 183.04,32.155 C183.426669,32.3116675 183.859998,32.39 184.34,32.39 C184.786669,32.39 185.171665,32.3383339 185.495,32.235 C185.818335,32.1316662 186.096666,32.0200006 186.33,31.9 C186.563335,31.7799994 186.758333,31.6683339 186.915,31.565 C187.071667,31.4616662 187.206666,31.41 187.32,31.41 C187.466667,31.41 187.58,31.4666661 187.66,31.58 L188.16,32.23 C187.939999,32.496668 187.676668,32.7283324 187.37,32.925 C187.063332,33.1216677 186.735002,33.2833327 186.385,33.41 C186.034998,33.5366673 185.673335,33.6316664 185.3,33.695 C184.926665,33.7583337 184.556669,33.79 184.19,33.79 C183.489997,33.79 182.845003,33.6716679 182.255,33.435 C181.664997,33.1983322 181.155002,32.851669 180.725,32.395 C180.294998,31.9383311 179.960001,31.3733367 179.72,30.7 C179.479999,30.0266633 179.36,29.2533377 179.36,28.38 C179.36,27.6733298 179.468332,27.0133364 179.685,26.4 C179.901668,25.7866636 180.213331,25.2550023 180.62,24.805 C181.026669,24.3549978 181.52333,24.001668 182.11,23.745 C182.69667,23.4883321 183.356663,23.36 184.09,23.36 Z M184.13,24.67 C183.269996,24.67 182.593336,24.9183309 182.1,25.415 C181.606664,25.9116692 181.300001,26.5999956 181.18,27.48 L186.69,27.48 C186.69,27.0666646 186.633334,26.6883351 186.52,26.345 C186.406666,26.001665 186.240001,25.7050013 186.02,25.455 C185.799999,25.2049988 185.531668,25.0116674 185.215,24.875 C184.898332,24.7383327 184.536669,24.67 184.13,24.67 Z M193.13,33.81 C192.329996,33.81 191.715002,33.5866689 191.285,33.14 C190.854998,32.6933311 190.64,32.0500042 190.64,31.21 L190.64,25.01 L189.42,25.01 C189.313333,25.01 189.223334,24.9783337 189.15,24.915 C189.076666,24.8516664 189.04,24.753334 189.04,24.62 L189.04,23.91 L190.7,23.7 L191.11,20.57 C191.123333,20.4699995 191.166666,20.3883337 191.24,20.325 C191.313334,20.2616664 191.406666,20.23 191.52,20.23 L192.42,20.23 L192.42,23.72 L195.32,23.72 L195.32,25.01 L192.42,25.01 L192.42,31.09 C192.42,31.5166688 192.523332,31.8333323 192.73,32.04 C192.936668,32.2466677 193.203332,32.35 193.53,32.35 C193.716668,32.35 193.878333,32.3250003 194.015,32.275 C194.151667,32.2249998 194.27,32.1700003 194.37,32.11 C194.470001,32.0499997 194.555,31.9950003 194.625,31.945 C194.695,31.8949998 194.756666,31.87 194.81,31.87 C194.903334,31.87 194.986666,31.9266661 195.06,32.04 L195.58,32.89 C195.273332,33.1766681 194.903336,33.4016659 194.47,33.565 C194.036665,33.7283342 193.590002,33.81 193.13,33.81 Z M195.7,23.52 L197.1,23.52 C197.246667,23.52 197.366666,23.5566663 197.46,23.63 C197.553334,23.7033337 197.616667,23.7899995 197.65,23.89 L199.59,30.41 C199.643334,30.6500012 199.693333,30.8816656 199.74,31.105 C199.786667,31.3283345 199.826667,31.5533322 199.86,31.78 C199.913334,31.5533322 199.973333,31.3283345 200.04,31.105 C200.106667,30.8816656 200.176666,30.6500012 200.25,30.41 L202.39,23.85 C202.423334,23.7499995 202.481666,23.666667 202.565,23.6 C202.648334,23.533333 202.753333,23.5 202.88,23.5 L203.65,23.5 C203.783334,23.5 203.893333,23.533333 203.98,23.6 C204.066667,23.666667 204.126667,23.7499995 204.16,23.85 L206.25,30.41 C206.323334,30.6433345 206.388333,30.8733322 206.445,31.1 C206.501667,31.3266678 206.556666,31.5499989 206.61,31.77 C206.643334,31.5499989 206.686666,31.3200012 206.74,31.08 C206.793334,30.8399988 206.85,30.6166677 206.91,30.41 L208.89,23.89 C208.923334,23.7833328 208.986666,23.6950004 209.08,23.625 C209.173334,23.5549997 209.283333,23.52 209.41,23.52 L210.75,23.52 L207.47,33.65 L206.06,33.65 C205.886666,33.65 205.766667,33.5366678 205.7,33.31 L203.46,26.44 C203.406666,26.2866659 203.363334,26.1316675 203.33,25.975 C203.296667,25.8183326 203.263334,25.6633341 203.23,25.51 C203.196667,25.6633341 203.163334,25.8199992 203.13,25.98 C203.096667,26.1400008 203.053334,26.2966659 203,26.45 L200.73,33.31 C200.656666,33.5366678 200.520001,33.65 200.32,33.65 L198.98,33.65 L195.7,23.52 Z M215.94,23.36 C216.680004,23.36 217.34833,23.4833321 217.945,23.73 C218.54167,23.9766679 219.048331,24.3266644 219.465,24.78 C219.881669,25.2333356 220.201666,25.7816635 220.425,26.425 C220.648334,27.0683366 220.76,27.7866627 220.76,28.58 C220.76,29.380004 220.648334,30.0999968 220.425,30.74 C220.201666,31.3800032 219.881669,31.9266644 219.465,32.38 C219.048331,32.8333356 218.54167,33.1816655 217.945,33.425 C217.34833,33.6683346 216.680004,33.79 215.94,33.79 C215.199996,33.79 214.53167,33.6683346 213.935,33.425 C213.33833,33.1816655 212.830002,32.8333356 212.41,32.38 C211.989998,31.9266644 211.666668,31.3800032 211.44,30.74 C211.213332,30.0999968 211.1,29.380004 211.1,28.58 C211.1,27.7866627 211.213332,27.0683366 211.44,26.425 C211.666668,25.7816635 211.989998,25.2333356 212.41,24.78 C212.830002,24.3266644 213.33833,23.9766679 213.935,23.73 C214.53167,23.4833321 215.199996,23.36 215.94,23.36 Z M215.94,32.4 C216.940005,32.4 217.686664,32.0650034 218.18,31.395 C218.673336,30.7249967 218.92,29.790006 218.92,28.59 C218.92,27.3833273 218.673336,26.4433367 218.18,25.77 C217.686664,25.0966633 216.940005,24.76 215.94,24.76 C215.433331,24.76 214.993335,24.8466658 214.62,25.02 C214.246665,25.1933342 213.935001,25.4433317 213.685,25.77 C213.434999,26.0966683 213.248334,26.498331 213.125,26.975 C213.001666,27.4516691 212.94,27.989997 212.94,28.59 C212.94,29.190003 213.001666,29.7266643 213.125,30.2 C213.248334,30.6733357 213.434999,31.0716651 213.685,31.395 C213.935001,31.718335 214.246665,31.9666658 214.62,32.14 C214.993335,32.3133342 215.433331,32.4 215.94,32.4 Z M222.46,33.65 L222.46,23.52 L223.48,23.52 C223.673334,23.52 223.806666,23.5566663 223.88,23.63 C223.953334,23.7033337 224.003333,23.8299991 224.03,24.01 L224.15,25.59 C224.496668,24.8833298 224.924997,24.3316687 225.435,23.935 C225.945003,23.5383314 226.54333,23.34 227.23,23.34 C227.510001,23.34 227.763332,23.3716664 227.99,23.435 C228.216668,23.4983337 228.426666,23.5866661 228.62,23.7 L228.39,25.03 C228.343333,25.1966675 228.240001,25.28 228.08,25.28 C227.986666,25.28 227.843334,25.2483337 227.65,25.185 C227.456666,25.1216664 227.186668,25.09 226.84,25.09 C226.219997,25.09 225.701669,25.2699982 225.285,25.63 C224.868331,25.9900018 224.520001,26.5133299 224.24,27.2 L224.24,33.65 L222.46,33.65 Z M231.87,18.92 L231.87,27.59 L232.33,27.59 C232.463334,27.59 232.573333,27.5716669 232.66,27.535 C232.746667,27.4983332 232.843333,27.4233339 232.95,27.31 L236.15,23.88 C236.250001,23.7733328 236.35,23.686667 236.45,23.62 C236.550001,23.553333 236.683333,23.52 236.85,23.52 L238.47,23.52 L234.74,27.49 C234.646666,27.6033339 234.555,27.7033329 234.465,27.79 C234.375,27.8766671 234.273334,27.953333 234.16,28.02 C234.280001,28.1000004 234.388333,28.1916662 234.485,28.295 C234.581667,28.3983339 234.673333,28.516666 234.76,28.65 L238.72,33.65 L237.12,33.65 C236.973333,33.65 236.848334,33.621667 236.745,33.565 C236.641666,33.5083331 236.543334,33.4200006 236.45,33.3 L233.12,29.15 C233.02,29.0099993 232.920001,28.9183336 232.82,28.875 C232.72,28.8316665 232.570001,28.81 232.37,28.81 L231.87,28.81 L231.87,33.65 L230.08,33.65 L230.08,18.92 L231.87,18.92 Z M138.08445,21.8888148 C139.013906,21.8888148 139.683662,22.1206727 140.093717,22.5843896 C140.503771,23.0481066 140.599451,23.8527908 140.380755,24.9984434 L138.494505,33.6726707 L136.1982,33.6726707 L137.920428,25.8167668 C138.029776,25.0529987 138.002439,24.5210886 137.838418,24.2210363 C137.674396,23.920984 137.291678,23.7709584 136.690265,23.7709584 C136.252874,23.7709584 135.801815,23.9482623 135.337086,24.3028686 C134.872357,24.657475 134.557982,25.2166634 134.393961,25.9804314 L132.753743,33.6726707 L130.457439,33.6726707 L132.917765,22.2161441 L135.050048,22.2161441 L134.804015,23.5254614 C135.186733,23.0344675 135.678798,22.6389449 136.280211,22.3388926 C136.881624,22.0388403 137.483038,21.8888148 138.08445,21.8888148 Z M139.068581,16.4060485 C138.63119,17.0607072 138.289477,17.756282 138.043445,18.492773 C137.797412,19.229264 137.619722,19.8975618 137.510374,20.4976652 L135.460102,20.4976652 C135.56945,19.7884512 135.760809,18.95649 136.034178,18.001779 C136.307547,17.0470681 136.799613,16.1059962 137.510374,15.1785635 C138.330483,13.9783556 139.546977,12.9690906 141.159858,12.1507672 C142.772739,11.3324439 144.836679,10.9505593 147.35168,11.0051146 L146.941625,13.0509229 C144.754668,13.0509229 143.073445,13.3782522 141.897956,14.0329109 C140.722467,14.6875695 139.779342,15.4786158 139.068581,16.4060485 Z M148.663854,24.7529464 L154.076572,24.7529464 L153.666517,26.7169224 L148.253799,26.7169224 L146.777603,33.6726707 L144.317277,33.6726707 L147.597712,18.4518568 L155.470757,18.4518568 L154.978691,20.4976652 L149.565973,20.4976652 L148.663854,24.7529464 Z M142.677059,22.2161441 L144.973364,22.2161441 L142.595049,33.6726707 L140.216733,33.6726707 L142.677059,22.2161441 Z M142.923092,20.4976652 C142.977766,20.3340005 143.032439,20.211252 143.087114,20.1294197 C143.141788,20.0475873 143.196461,19.9248388 143.251136,19.7611742 C143.524505,19.4338448 143.852548,19.1474317 144.235266,18.9019347 C144.617984,18.6564377 145.137386,18.5064121 145.793473,18.4518568 L145.301408,20.4976652 L142.923092,20.4976652 Z M153.994561,33.6726707 L156.372876,22.2161441 L158.751192,22.2161441 L156.290865,33.6726707 L153.994561,33.6726707 Z M129.473308,18.4518568 L131.933634,18.4518568 L129.63733,29.1718924 C129.254612,30.9176484 128.571188,32.1587725 127.587058,32.8952635 C126.602927,33.6317545 125.372764,34 123.896568,34 C122.420372,34 121.340562,33.6317545 120.657139,32.8952635 C119.973715,32.1587725 119.823361,30.9176484 120.206079,29.1718924 L122.502383,18.4518568 L124.96271,18.4518568 L122.666405,29.3355571 C122.447709,30.317545 122.475047,31.026759 122.748416,31.4631977 C123.021785,31.8996364 123.541188,32.1178564 124.306623,32.1178564 C125.017384,32.1178564 125.605128,31.8996364 126.069857,31.4631977 C126.534585,31.026759 126.876296,30.317545 127.094993,29.3355571 L129.473308,18.4518568 Z M146.613582,14.5239049 L146.121516,16.5697132 C145.028038,16.6788226 144.153255,16.9379587 143.497168,17.3471204 C142.841081,17.756282 142.349016,18.2063598 142.020972,18.6973538 C141.802276,18.9701279 141.624587,19.2701802 141.487902,19.5975095 C141.351216,19.9248388 141.2282,20.2248911 141.118853,20.4976652 L139.068581,20.4976652 C139.177928,20.0066712 139.328282,19.5156772 139.519641,19.0246832 C139.710999,18.5336892 139.998037,18.0154181 140.380755,17.4698689 C140.927494,16.7061008 141.720265,16.037803 142.75907,15.4649767 C143.797875,14.8921504 145.082711,14.5784602 146.613582,14.5239049 Z M157.192985,18.4518568 L158.054099,18.8201023 C158.628175,19.0655993 158.915213,19.6247865 158.915213,20.4976652 L156.70092,20.4976652 L157.192985,18.4518568 Z"></path></g></svg>
                            <h3 align="center">Stats login</h3>
                            <br>
                            <div id="login_alert_placeholder" style="display: none"></div>
                            <form method="post">
                                <?php if (empty($controller['user'])) : ?>
                                    <div class="form-group">
                                        <label for="input_controller_user">Username</label>
                                        <input type="text" id="input_controller_user" name="controller_user" class="form-control" placeholder="Controller username">
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($controller['password'])) : ?>
                                    <div class="form-group">
                                        <label for="input_controller_password">Password</label>
                                        <input type="password" id="input_controller_password" name="controller_password" class="form-control" placeholder="Controller password">
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($controller['url'])) : ?>
                                    <div class="form-group">
                                        <label for="input_controller_url">URL</label>
                                        <input type="text" id="input_controller_url" name="controller_url" class="form-control" placeholder="https://<controller FQDN or IP>:8443" value="https://unifi.paulamato.com.au/">
                                    </div>
                                <?php endif; ?>
                                <input type="submit" name="login" class="btn btn-primary pull-right" value="Login">
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /login_form -->
            <!-- data-panel, only to be displayed once a controller has been configured and an action has been selected, while loading we display a temp div -->
            <?php if (isset($_SESSION['unificookie']) && $action) { ?>
            <div id="output_panel_loading" class="text-center">
                <br>
                <h2><i class="fa fa-spinner fa-spin fa-fw"></i></h2>
            </div>
            
            <?php } ?>
            <!-- /data-panel -->
        </div>
        <!-- back-to-top button element -->
        <a id="back-to-top" href="#" class="btn btn-primary back-to-top" role="button" title="Back to top" data-toggle="tooltip" data-placement="left"><i class="fa fa-chevron-up" aria-hidden="true"></i></a>
        <!-- /back-to-top button element -->
        <!-- About modal -->
        <div class="modal fade" id="about_modal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="myModalLabel"><i class="fa fa-info-circle"></i> About UniFi API browser</h4>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-sm-10 col-sm-offset-1">
                                A tool for browsing the data collections which are exposed through Ubiquiti's UniFi Controller API.
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-8 col-sm-offset-1"><a href="http://www.dereferer.org/?https://github.com/Art-of-WiFi/UniFi-API-browser"
                            target="_blank">UniFi API browser on Github</a></div>
                            <div class="col-sm-8 col-sm-offset-1"><a href="http://www.dereferer.org/?http://community.ubnt.com/t5/UniFi-Wireless/UniFi-API-browser-tool-updates-and-discussion/m-p/1392651#U1392651"
                            target="_blank">UniFi API browser on Ubiquiti Community forum</a></div>
                            <div class="col-sm-8 col-sm-offset-1"><a href="http://www.dereferer.org/?https://github.com/Art-of-WiFi/UniFi-API-client"
                            target="_blank">UniFi API client on Github</a></div>
                        </div>
                        <hr>
                        <dl class="dl-horizontal col-sm-offset-2">
                            <dt>API browser version</dt>
                            <dd><span id="span_api_browser_version" class="label label-primary"></span> <span id="span_api_browser_update" class="label label-success"><i class="fa fa-spinner fa-spin fa-fw"></i> checking for updates</span></dd>
                            <dt>API client version</dt>
                            <dd><span id="span_api_class_version" class="label label-primary"></span></dd>
                        </dl>
                        <hr>
                        <dl class="dl-horizontal col-sm-offset-2">
                            <dt>controller user</dt>
                            <dd><span id="span_controller_user" class="label label-primary"></span></dd>
                            <dt>controller URL</dt>
                            <dd><span id="span_controller_url" class="label label-primary"></span></dd>
                            <dt>version detected</dt>
                            <dd><span id="span_controller_version" class="label label-primary"></span></dd>
                        </dl>
                        <hr>
                        <dl class="dl-horizontal col-sm-offset-2">
                            <dt>PHP version</dt>
                            <dd><span id="span_php_version" class="label label-primary"></span></dd>
                            <dt>PHP memory_limit</dt>
                            <dd><span id="span_memory_limit" class="label label-primary"></span></dd>
                            <dt>PHP memory used</dt>
                            <dd><span id="span_memory_used" class="label label-primary"></span></dd>
                            <dt>cURL version</dt>
                            <dd><span id="span_curl_version" class="label label-primary"></span></dd>
                            <dt>OpenSSL version</dt>
                            <dd><span id="span_openssl_version" class="label label-primary"></span></dd>
                            <dt>operating system</dt>
                            <dd><span id="span_os_version" class="label label-primary"></span></dd>
                        </dl>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php if (isset($_SESSION['unificookie'])) { ?>
          <?php
            date_default_timezone_set('Australia/Melbourne');
            $current_day = date("d");
            $current_month = date("m");
            $current_year = date("Y");
          ?>
          <div class="usage-form">
            <form>
              <div class="dates">
                <div>
                  <label>From</label>
                  <select name="from_d">
                    <option<?php if ($_GET["from_d"] == '01') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '1') { echo ' selected'; } ?>>01</option>
                    <option<?php if ($_GET["from_d"] == '02') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '2') { echo ' selected'; } ?>>02</option>
                    <option<?php if ($_GET["from_d"] == '03') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '3') { echo ' selected'; } ?>>03</option>
                    <option<?php if ($_GET["from_d"] == '04') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '4') { echo ' selected'; } ?>>04</option>
                    <option<?php if ($_GET["from_d"] == '05') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '5') { echo ' selected'; } ?>>05</option>
                    <option<?php if ($_GET["from_d"] == '06') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '6') { echo ' selected'; } ?>>06</option>
                    <option<?php if ($_GET["from_d"] == '07') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '7') { echo ' selected'; } ?>>07</option>
                    <option<?php if ($_GET["from_d"] == '08') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '8') { echo ' selected'; } ?>>08</option>
                    <option<?php if ($_GET["from_d"] == '09') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '9') { echo ' selected'; } ?>>09</option>
                    <option<?php if ($_GET["from_d"] == '10') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '10') { echo ' selected'; } ?>>10</option>
                    <option<?php if ($_GET["from_d"] == '11') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '11') { echo ' selected'; } ?>>11</option>
                    <option<?php if ($_GET["from_d"] == '12') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '12') { echo ' selected'; } ?>>12</option>
                    <option<?php if ($_GET["from_d"] == '13') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '13') { echo ' selected'; } ?>>13</option>
                    <option<?php if ($_GET["from_d"] == '14') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '14') { echo ' selected'; } ?>>14</option>
                    <option<?php if ($_GET["from_d"] == '15') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '15') { echo ' selected'; } ?>>15</option>
                    <option<?php if ($_GET["from_d"] == '16') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '16') { echo ' selected'; } ?>>16</option>
                    <option<?php if ($_GET["from_d"] == '17') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '17') { echo ' selected'; } ?>>17</option>
                    <option<?php if ($_GET["from_d"] == '18') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '18') { echo ' selected'; } ?>>18</option>
                    <option<?php if ($_GET["from_d"] == '19') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '19') { echo ' selected'; } ?>>19</option>
                    <option<?php if ($_GET["from_d"] == '20') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '20') { echo ' selected'; } ?>>20</option>
                    <option<?php if ($_GET["from_d"] == '21') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '21') { echo ' selected'; } ?>>21</option>
                    <option<?php if ($_GET["from_d"] == '22') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '22') { echo ' selected'; } ?>>22</option>
                    <option<?php if ($_GET["from_d"] == '23') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '23') { echo ' selected'; } ?>>23</option>
                    <option<?php if ($_GET["from_d"] == '24') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '24') { echo ' selected'; } ?>>24</option>
                    <option<?php if ($_GET["from_d"] == '25') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '25') { echo ' selected'; } ?>>25</option>
                    <option<?php if ($_GET["from_d"] == '26') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '26') { echo ' selected'; } ?>>26</option>
                    <option<?php if ($_GET["from_d"] == '27') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '27') { echo ' selected'; } ?>>27</option>
                    <option<?php if ($_GET["from_d"] == '28') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '28') { echo ' selected'; } ?>>28</option>
                    <option<?php if ($_GET["from_d"] == '29') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '29') { echo ' selected'; } ?>>29</option>
                    <option<?php if ($_GET["from_d"] == '30') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '30') { echo ' selected'; } ?>>30</option>
                    <option<?php if ($_GET["from_d"] == '31') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '31') { echo ' selected'; } ?>>31</option>
                  </select>
                  <select name="from_m">
                    <option<?php if ($_GET["from_m"] == '01') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '01') { echo ' selected'; } ?>>01</option>
                    <option<?php if ($_GET["from_m"] == '02') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '02') { echo ' selected'; } ?>>02</option>
                    <option<?php if ($_GET["from_m"] == '03') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '03') { echo ' selected'; } ?>>03</option>
                    <option<?php if ($_GET["from_m"] == '04') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '04') { echo ' selected'; } ?>>04</option>
                    <option<?php if ($_GET["from_m"] == '05') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '05') { echo ' selected'; } ?>>05</option>
                    <option<?php if ($_GET["from_m"] == '06') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '06') { echo ' selected'; } ?>>06</option>
                    <option<?php if ($_GET["from_m"] == '07') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '07') { echo ' selected'; } ?>>07</option>
                    <option<?php if ($_GET["from_m"] == '08') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '08') { echo ' selected'; } ?>>08</option>
                    <option<?php if ($_GET["from_m"] == '09') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '09') { echo ' selected'; } ?>>09</option>
                    <option<?php if ($_GET["from_m"] == '10') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '10') { echo ' selected'; } ?>>10</option>
                    <option<?php if ($_GET["from_m"] == '11') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '11') { echo ' selected'; } ?>>11</option>
                    <option<?php if ($_GET["from_m"] == '12') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '12') { echo ' selected'; } ?>>12</option>
                  </select>
                  <select name="from_y">
                    <option<?php if ($_GET["from_y"] == '2019') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2019') { echo ' selected'; } ?>>2019</option>
                    <option<?php if ($_GET["from_y"] == '2020') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2020') { echo ' selected'; } ?>>2020</option>
                    <option<?php if ($_GET["from_y"] == '2021') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2021') { echo ' selected'; } ?>>2021</option>
                    <option<?php if ($_GET["from_y"] == '2022') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2022') { echo ' selected'; } ?>>2022</option>
                    <option<?php if ($_GET["from_y"] == '2023') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2023') { echo ' selected'; } ?>>2023</option>
                    <option<?php if ($_GET["from_y"] == '2024') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2024') { echo ' selected'; } ?>>2024</option>
                    <option<?php if ($_GET["from_y"] == '2025') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2025') { echo ' selected'; } ?>>2025</option>
                  </select>
                </div>
                <div>
                  <label>To</label>
                  <select name="to_d">
                    <option<?php if ($_GET["to_d"] == '01') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '1') { echo ' selected'; } ?>>01</option>
                    <option<?php if ($_GET["to_d"] == '02') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '2') { echo ' selected'; } ?>>02</option>
                    <option<?php if ($_GET["to_d"] == '03') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '3') { echo ' selected'; } ?>>03</option>
                    <option<?php if ($_GET["to_d"] == '04') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '4') { echo ' selected'; } ?>>04</option>
                    <option<?php if ($_GET["to_d"] == '05') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '5') { echo ' selected'; } ?>>05</option>
                    <option<?php if ($_GET["to_d"] == '06') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '6') { echo ' selected'; } ?>>06</option>
                    <option<?php if ($_GET["to_d"] == '07') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '7') { echo ' selected'; } ?>>07</option>
                    <option<?php if ($_GET["to_d"] == '08') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '8') { echo ' selected'; } ?>>08</option>
                    <option<?php if ($_GET["to_d"] == '09') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '9') { echo ' selected'; } ?>>09</option>
                    <option<?php if ($_GET["to_d"] == '10') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '10') { echo ' selected'; } ?>>10</option>
                    <option<?php if ($_GET["to_d"] == '11') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '11') { echo ' selected'; } ?>>11</option>
                    <option<?php if ($_GET["to_d"] == '12') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '12') { echo ' selected'; } ?>>12</option>
                    <option<?php if ($_GET["to_d"] == '13') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '13') { echo ' selected'; } ?>>13</option>
                    <option<?php if ($_GET["to_d"] == '14') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '14') { echo ' selected'; } ?>>14</option>
                    <option<?php if ($_GET["to_d"] == '15') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '15') { echo ' selected'; } ?>>15</option>
                    <option<?php if ($_GET["to_d"] == '16') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '16') { echo ' selected'; } ?>>16</option>
                    <option<?php if ($_GET["to_d"] == '17') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '17') { echo ' selected'; } ?>>17</option>
                    <option<?php if ($_GET["to_d"] == '18') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '18') { echo ' selected'; } ?>>18</option>
                    <option<?php if ($_GET["to_d"] == '19') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '19') { echo ' selected'; } ?>>19</option>
                    <option<?php if ($_GET["to_d"] == '20') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '20') { echo ' selected'; } ?>>20</option>
                    <option<?php if ($_GET["to_d"] == '21') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '21') { echo ' selected'; } ?>>21</option>
                    <option<?php if ($_GET["to_d"] == '22') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '22') { echo ' selected'; } ?>>22</option>
                    <option<?php if ($_GET["to_d"] == '23') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '23') { echo ' selected'; } ?>>23</option>
                    <option<?php if ($_GET["to_d"] == '24') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '24') { echo ' selected'; } ?>>24</option>
                    <option<?php if ($_GET["to_d"] == '25') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '25') { echo ' selected'; } ?>>25</option>
                    <option<?php if ($_GET["to_d"] == '26') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '26') { echo ' selected'; } ?>>26</option>
                    <option<?php if ($_GET["to_d"] == '27') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '27') { echo ' selected'; } ?>>27</option>
                    <option<?php if ($_GET["to_d"] == '28') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '28') { echo ' selected'; } ?>>28</option>
                    <option<?php if ($_GET["to_d"] == '29') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '29') { echo ' selected'; } ?>>29</option>
                    <option<?php if ($_GET["to_d"] == '30') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '30') { echo ' selected'; } ?>>30</option>
                    <option<?php if ($_GET["to_d"] == '31') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '31') { echo ' selected'; } ?>>31</option>
                  </select>
                  <select name="to_m">
                    <option<?php if ($_GET["to_m"] == '01') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '01') { echo ' selected'; } ?>>01</option>
                    <option<?php if ($_GET["to_m"] == '02') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '02') { echo ' selected'; } ?>>02</option>
                    <option<?php if ($_GET["to_m"] == '03') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '03') { echo ' selected'; } ?>>03</option>
                    <option<?php if ($_GET["to_m"] == '04') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '04') { echo ' selected'; } ?>>04</option>
                    <option<?php if ($_GET["to_m"] == '05') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '05') { echo ' selected'; } ?>>05</option>
                    <option<?php if ($_GET["to_m"] == '06') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '06') { echo ' selected'; } ?>>06</option>
                    <option<?php if ($_GET["to_m"] == '07') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '07') { echo ' selected'; } ?>>07</option>
                    <option<?php if ($_GET["to_m"] == '08') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '08') { echo ' selected'; } ?>>08</option>
                    <option<?php if ($_GET["to_m"] == '09') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '09') { echo ' selected'; } ?>>09</option>
                    <option<?php if ($_GET["to_m"] == '10') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '10') { echo ' selected'; } ?>>10</option>
                    <option<?php if ($_GET["to_m"] == '11') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '11') { echo ' selected'; } ?>>11</option>
                    <option<?php if ($_GET["to_m"] == '12') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '12') { echo ' selected'; } ?>>12</option>
                  </select>
                  <select name="to_y">
                    <option<?php if ($_GET["to_y"] == '2019') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2019') { echo ' selected'; } ?>>2019</option>
                    <option<?php if ($_GET["to_y"] == '2020') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2020') { echo ' selected'; } ?>>2020</option>
                    <option<?php if ($_GET["to_y"] == '2021') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2021') { echo ' selected'; } ?>>2021</option>
                    <option<?php if ($_GET["to_y"] == '2022') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2022') { echo ' selected'; } ?>>2022</option>
                    <option<?php if ($_GET["to_y"] == '2023') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2023') { echo ' selected'; } ?>>2023</option>
                    <option<?php if ($_GET["to_y"] == '2024') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2024') { echo ' selected'; } ?>>2024</option>
                    <option<?php if ($_GET["to_y"] == '2025') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2025') { echo ' selected'; } ?>>2025</option>
                  </select><br />
                </div>
              </div>
              <input type="submit" name="submit" value="Submit">
            </form>
            <form>
              <div>
                <label>Last number of days</label>
                <select name="days">
                  <option<?php if ($_GET["days"] == '1') { echo ' selected'; } ?>>1</option>
                  <option<?php if ($_GET["days"] == '2') { echo ' selected'; } ?>>2</option>
                  <option<?php if ($_GET["days"] == '3') { echo ' selected'; } ?>>3</option>
                  <option<?php if ($_GET["days"] == '7') { echo ' selected'; } ?>>7</option>
                  <option<?php if ($_GET["days"] == '30') { echo ' selected'; } elseif ($_GET["days"] == '') { echo ' selected';} ?>>30</option>
                  <option<?php if ($_GET["days"] == '60') { echo ' selected'; } ?>>60</option>
                  <option<?php if ($_GET["days"] == '90') { echo ' selected'; } ?>>90</option>
                  <option<?php if ($_GET["days"] == '120') { echo ' selected'; } ?>>120</option>
                  <option<?php if ($_GET["days"] == '365') { echo ' selected'; } ?>>365</option>
                </select>
              </div>
              <input type="submit" name="submit" value="Submit">
            </form>
          </div>
        <?php } ?>

        <!-- latest compiled and minified JavaScript versions, loaded from CDN's, now including Source Integrity hashes, just in case... -->
        <script src="https://code.jquery.com/jquery-2.2.4.min.js" integrity="sha384-rY/jv8mMhqDabXSo+UCggqKtdmBfd3qC2/KvyTDNQ6PcUJXaxK1tMepoQda4g5vB" crossorigin="anonymous"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-jsonview/1.2.3/jquery.jsonview.min.js" integrity="sha384-DmFpgjLdJIZgLscJ9DpCHynOVhvNfaTvPJA+1ijsbkMKhI/e8eKnBZp1AmHDVjrT" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.1/clipboard.min.js" integrity="sha384-JbuAF+8+4FF8uR/7D07/5IWwIj2FoNw5jJQGg1s8GtGIfQXFxWPRdMNWYmU35KjP" crossorigin="anonymous"></script>
        <script>
            /**
             * populate some global Javascript variables with PHP output for cleaner code
             */
            var alert_message       = '<?php echo $alert_message ?>',
                show_login          = '<?php echo $show_login ?>',
                action              = '<?php echo $action ?>',
                site_id             = '<?php echo $site_id ?>',
                site_name           = '<?php echo htmlspecialchars($site_name) ?>',
                controller_id       = '<?php echo $controller_id ?>',
                output_format       = '<?php echo $output_format ?>',
                selection           = '<?php echo $selection ?>',
                objects_count       = '<?php echo $objects_count ?>',
                timing_login_perc   = '<?php echo $login_perc ?>',
                time_after_login    = '<?php echo $time_after_login ?>',
                timing_load_perc    = '<?php echo $load_perc ?>',
                time_for_load       = '<?php echo ($time_after_load - $time_after_login) ?>',
                timing_remain_perc  = '<?php echo $remain_perc ?>',
                timing_total_time   = '<?php echo $time_total ?>',
                php_version         = '<?php echo (phpversion()) ?>',
                memory_limit        = '<?php echo (ini_get('memory_limit')) ?>',
                memory_used         = '<?php echo round(memory_get_peak_usage(false) / 1024 / 1024, 2) . 'M' ?>',
                curl_version        = '<?php echo $curl_version ?>',
                openssl_version     = '<?php echo $openssl_version ?>',
                os_version          = '<?php echo (php_uname('s') . ' ' . php_uname('r')) ?>',
                api_browser_version = '<?php echo API_BROWSER_VERSION ?>',
                api_class_version   = '<?php echo API_CLASS_VERSION ?>',
                controller_user     = '<?php if (isset($controller['user'])) echo $controller['user'] ?>',
                controller_url      = '<?php if (isset($controller['url'])) echo $controller['url'] ?>',
                controller_version  = '<?php if (isset($detected_controller_version)) echo $detected_controller_version ?>';
                theme               = 'bootstrap';

            /**
             * check whether user has stored a custom theme, if yes we switch to the stored value
             */
            if (localStorage.getItem('API_browser_theme') == null || localStorage.getItem('API_browser_theme') === 'bootstrap') {
                $('#bootstrap').addClass('active').find('a').append(' <i class="fa fa-check"></i>');
            } else {
                var stored_theme = localStorage.getItem('API_browser_theme');
                switchCSS(stored_theme);
            }

            /**
             * process a Bootswatch CSS stylesheet change
             */
            function switchCSS(new_theme) {
                console.log('current theme: ' + theme + ' new theme: ' + new_theme);
                if (new_theme === 'bootstrap') {
                    $('#bootswatch_theme_stylesheet').attr('href', '');
                } else {
                    $('#bootswatch_theme_stylesheet').attr('href', 'https://maxcdn.bootstrapcdn.com/bootswatch/3.3.7/' + new_theme + '/bootstrap.min.css');
                }

                $('#' + theme).removeClass('active').find('a').children('i').remove();
                $('#' + new_theme).addClass('active').find('a').append(' <i class="fa fa-check"></i>');
                theme = new_theme;
                localStorage.setItem('API_browser_theme', theme);
            }

            $(document).ready(function () {
                /**
                 * we hide the loading div and show the output panel
                 */
                $('#output_panel_loading').hide();
                $('#output_panel').show();

                /**
                 * if needed we display the login form
                 */
                if (show_login == 1 || show_login == 'true') {
                    $('#login_alert_placeholder').html(alert_message);
                    $('#login_alert_placeholder').show();
                    $('#login_form').fadeIn(500);
                } else {
                    $('#alert_placeholder').html(alert_message);
                    $('#alert_placeholder').fadeIn(500);
                }

                /**
                 * update dynamic elements in the DOM using some of the above variables
                 */
                $('#span_site_id').html(site_id);
                $('#span_site_name').html(site_name);
                $('#span_output_format').html(output_format);
                $('#span_selection').html(selection);

                if (action.includes('(') || action.includes('(')) {
                    $('#span_function').html(action);
                } else {
                    $('#span_function').html(action + '()');
                }

                $('#span_objects_count').html(objects_count);
                $('#span_elapsed_time').html('total elapsed time: ' + timing_total_time + ' seconds');

                $('#timing_login_perc').attr('aria-valuenow', timing_login_perc);
                $('#timing_login_perc').css('width', timing_login_perc + '%');
                $('#timing_login_perc').attr('data-original-title', time_after_login + ' seconds');
                $('#timing_login_perc').html('API login time');
                $('#timing_load_perc').attr('aria-valuenow', timing_load_perc);
                $('#timing_load_perc').css('width', timing_load_perc + '%');
                $('#timing_load_perc').attr('data-original-title', time_for_load + ' seconds');
                $('#timing_load_perc').html('data load time');
                $('#timing_remain_perc').attr('aria-valuenow', timing_remain_perc);
                $('#timing_remain_perc').css('width', timing_remain_perc + '%');
                $('#timing_remain_perc').attr('data-original-title', 'PHP overhead: ' + timing_remain_perc + '%');
                $('#timing_remain_perc').html('PHP overhead');

                $('#span_api_browser_version').html(api_browser_version);
                $('#span_api_class_version').html(api_class_version);
                $('#span_controller_user').html(controller_user);
                $('#span_controller_url').html(controller_url);
                $('#span_controller_version').html(controller_version);
                $('#span_php_version').html(php_version);
                $('#span_curl_version').html(curl_version);
                $('#span_openssl_version').html(openssl_version);
                $('#span_os_version').html(os_version);
                $('#span_memory_limit').html(memory_limit);
                $('#span_memory_used').html(memory_used);

                /**
                 * highlight and mark the selected options in the dropdown menus for $controller_id, $action, $site_id, $theme and $output_format
                 *
                 * NOTE:
                 * these actions are performed conditionally
                 */
                (action != '') ? $('#' + action).addClass('active').find('a').append(' <i class="fa fa-check"></i>') : false;
                (site_id != '') ? $('#' + site_id).addClass('active').find('a').append(' <i class="fa fa-check"></i>') : false;
                (controller_id != '') ? $('#controller_' + controller_id).addClass('active').find('a').append(' <i class="fa fa-check"></i>') : false;

                /**
                 * these two options have default values so no tests needed here
                 */
                $('#' + output_format).addClass('active').find('a').append(' <i class="fa fa-check"></i>');

                /**
                 * initialise the jquery-jsonview library, only when required
                 */
                if (output_format == 'json_color') {
                    $('#toggle_buttons').show();
                    $('#pre_output').JSONView($('#pre_output').text());

                    /**
                     * the expand/collapse toggle buttons to control the json view
                     */
                    $('#toggle-btn').on('click', function () {
                        $('#pre_output').JSONView('toggle');
                        $('#i_toggle-btn').toggleClass('fa-plus').toggleClass('fa-minus');
                        $(this).blur();
                    });

                    $('#toggle-level2-btn').on('click', function () {
                        $('#pre_output').JSONView('toggle', 2);
                        $('#i_toggle-level2-btn').toggleClass('fa-plus').toggleClass('fa-minus');
                        $(this).blur();
                    });
                }

                /**
                 * only now do we display the output
                 */
                $('#output').show();

                /**
                 * check latest version of API browser tool and inform user when it's more recent than the current,
                 * but only when the "about" modal is opened
                 */
                $('#about_modal').on('shown.bs.modal', function (e) {
                    $.getJSON('https://api.github.com/repos/Art-of-WiFi/UniFi-API-browser/releases/latest', function (external) {
                        if (api_browser_version != '' && typeof(external.tag_name) !== 'undefined') {
                            if (api_browser_version < external.tag_name.substring(1)) {
                                $('#span_api_browser_update').html('an update is available: ' + external.tag_name.substring(1));
                                $('#span_api_browser_update').removeClass('label-success').addClass('label-warning');
                            } else if (api_browser_version == external.tag_name.substring(1)) {
                                $('#span_api_browser_update').html('up to date');
                            } else {
                                $('#span_api_browser_update').html('bleeding edge!');
                                $('#span_api_browser_update').removeClass('label-success').addClass('label-danger');
                            }
                        }
                    }).fail(function (d, textStatus, error) {
                        $('#span_api_browser_update').html('error checking updates');
                        $('#span_api_browser_update').removeClass('label-success').addClass('label-danger');
                        console.error('getJSON failed, status: ' + textStatus + ', error: ' + error);
                    });;
                })

                /**
                 * and reset the span again when the "about" modal is closed
                 */
                $('#about_modal').on('hidden.bs.modal', function (e) {
                    $('#span_api_browser_update').html('<i class="fa fa-spinner fa-spin fa-fw"></i> checking for updates</span>');
                    $('#span_api_browser_update').removeClass('label-warning').removeClass('label-danger').addClass('label-success');
                })

                /**
                 * initialize the "copy to clipboard" function, "borrowed" from the UserFrosting framework
                 */
                if (typeof $.uf === 'undefined') {
                    $.uf = {};
                }

                $.uf.copy = function (button) {
                    var _this = this;

                    var clipboard = new ClipboardJS(button, {
                        text: function (trigger) {
                            var el = $(trigger).closest('.js-copy-container').find('.js-copy-target');
                            if (el.is(':input')) {
                                return el.val();
                            } else {
                                return el.html();
                            }
                        }
                    });

                    clipboard.on('success', function (e) {
                        setTooltip(e.trigger, 'Copied!');
                        hideTooltip(e.trigger);
                    });

                    clipboard.on('error', function (e) {
                        setTooltip(e.trigger, 'Failed!');
                        hideTooltip(e.trigger);
                        console.log('Copy to clipboard failed, most probably the selection is too large');
                    });

                    function setTooltip(btn, message) {
                        $(btn)
                        .attr('data-original-title', message)
                        .tooltip('show');
                    }

                    function hideTooltip(btn) {
                        setTimeout(function () {
                            $(btn).tooltip('hide')
                            .attr('data-original-title', 'Copy to clipboard');
                        }, 500);
                    }

                    /**
                     * tooltip trigger
                     */
                    $(button).tooltip({
                        trigger: 'hover'
                    });
                };

                /**
                 * link the copy button
                 */
                $.uf.copy('.js-copy-trigger');

                /**
                 * hide "copy to clipboard" button if the ClipboardJS function isn't supported or the output format isn't supported
                 */
                var unsupported_formats = [
                    'json',
                    'php_array',
                    'php_var_dump',
                    'php_var_export'
                ];
                if (!ClipboardJS.isSupported() || $.inArray(output_format, unsupported_formats) === -1) {
                    $('.js-copy-trigger').hide();
                }

                /**
                 * manage display of the "back to top" button element
                 */
                $(window).scroll(function () {
                    if ($(this).scrollTop() > 50) {
                        $('#back-to-top').fadeIn();
                    } else {
                        $('#back-to-top').fadeOut();
                    }
                });

                /**
                 * scroll body to 0px (top) on click on the "back to top" button
                 */
                $('#back-to-top').click(function () {
                    $('#back-to-top').tooltip('hide');
                    $('body,html').animate({
                        scrollTop: 0
                    }, 500);
                    return false;
                });

                $('#back-to-top').tooltip('show');

                /**
                 * enable Bootstrap tooltips
                 */
                $(function () {
                    $('[data-toggle="tooltip"]').tooltip()
                })
            });
        </script>


        <script>
          $( document ).ready(function() {

            $grandTotal = 0;
            $grandTotal_raw = 0;
            obj = $data['data'];

            Object.keys(obj).forEach(function(key) {


                //console.log(key, obj[key]);

                $date_raw = obj[key]['time'];
                $dateMoment = moment($date_raw).format('YYYY-MM-DD');
                $dateMomentPretty = moment($date_raw).format('Do MMM YYYY');

                $currentDate = moment().format();
                $days = -1 * moment($dateMoment).diff($currentDate, 'days');

                <?php
                  $days = $_GET["days"];
                  if (!$_GET["days"]) {
                    $days = 30;
                  }

                  $from_d = $_GET["from_d"];
                  $from_m = $_GET["from_m"];
                  $from_y = $_GET["from_y"];
                  $to_d = $_GET["to_d"];
                  $to_m = $_GET["to_m"];
                  $to_y = $_GET["to_y"];
                  $startDate = $from_y . '-' . $from_m . '-' . $from_d;
                  $endDate = $to_y . '-' . $to_m . '-' . $to_d;

                ?>
                <?php if ($_GET["from_d"] && $_GET["from_m"] && $_GET["from_y"]): ?>
                  
                  $startDate_raw = new Date('<?php echo $startDate; ?>').getTime();
                  $startDateMoment = moment($startDate_raw).format('YYYY-MM-DD');
                  $endDate_raw = new Date('<?php echo $endDate; ?>').getTime();
                  $endDateMoment = moment($endDate_raw).format('YYYY-MM-DD');

                  if (moment($startDateMoment).isSameOrBefore($dateMoment) && moment($endDateMoment).isSameOrAfter($dateMoment)) {
                    writeData();
                  }
                <?php else: ?>
                  if ($days <= <?php echo $days; ?>) {
                    writeData();
                  }
                <?php endif; ?>

                function writeData() {
                  $down_raw = obj[key]['wan-rx_bytes'] / 1073741824;
                  $down = Math.round( $down_raw * 100 ) / 100;
                  $up_raw = obj[key]['wan-tx_bytes'] / 1073741824;
                  $up = Math.round( $up_raw * 100 ) / 100;

                  $total_raw = $down_raw + $up_raw;
                  $total = Math.round( $total_raw * 100 ) / 100;

                  $usage_line = '';
                  $usage_line += '<div class="usage-line">';
                    $usage_line += '<div>' + $dateMomentPretty + ' <span>(' + $days + ' days ago)</span></div>';
                    $usage_line += '<div>' + $up + 'GB</div>';
                    $usage_line += '<div>' + $down + 'GB</div>';
                    $usage_line += '<div>' + $total + 'GB</div>';
                  $usage_line += '</div>';
                  $('.usage').append($usage_line);

                  $grandTotal_raw += $up_raw;
                  $grandTotal_raw += $down_raw;
                }
                
            });
            
            $grandTotal = Math.round( $grandTotal_raw * 100 ) / 100;
            $('.usage').prepend('<h2>Total: ' + $grandTotal + 'GB</h2>');

            <?php if ($from_d && $from_m && $from_y && $to_d && $to_m && $to_y): ?>
              $('.usage').prepend('<div class="seperator"></div><h5>Usage from <?php echo $from_d . '/' . $from_m . '/' . $from_y . ' to ' . $to_d . '/' . $to_m . '/' . $to_y; ?></h5>');
            <?php elseif ($days == ''): ?>
              $('.usage').prepend('<div class="seperator"></div><h5>Usage over the last the 30 days</h5>');
            <?php else: ?>
              $('.usage').prepend('<div class="seperator"></div><h5>Usage over the last the <?php echo $days; ?> days</h5>');
            <?php endif; ?>
          });
        </script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment.min.js"></script>
        <?php if (isset($_SESSION['unificookie'])) : ?>
          <div class="usage">
            <div class="usage-headings">
              <div>Date</div>
              <div>Uploads</div>
              <div>Downloads</div>
              <div>Total</div>
            </div>
          </div>
        <?php endif; ?>

        <style>
          .navbar-default {
            background-color: #242635;
            color: white;
            border: none;
          }
          .navbar-brand {
            padding: 13px 15px;
            color: white;
          }
          .navbar-default .navbar-nav>li>a {
            color: white;
          }
          .navbar-default .navbar-nav>li>a:focus, .navbar-default .navbar-nav>li>a:hover {
            background-color: #1C1E2D;
            color: white;
          }
          .navbar-default .navbar-nav>.open>a, .navbar-default .navbar-nav>.open>a:focus, .navbar-default .navbar-nav>.open>a:hover {
            background-color: #1C1E2D;
            color: white;
          }
          #site-menu {
            display: none;
          }
          .dropdown-menu {
            background-color: #242635;
            color: #7e8190;
          }
          .dropdown-menu .divider {
            background-color: #323442;
          }
          .dropdown-menu>li>a {
            color: white;
          }
          .dropdown-menu>li>a:focus, .dropdown-menu>li>a:hover {
            color: #ffffff;
            background-color: #1c1e2d;
          }
          .navbar-brand svg {
            color: white;
            margin: 0 0 0 6px;
          }
          .dropdown-header {
            color: white;
          }
          .navbar-nav>li>a {
            padding-top: 20px;
            padding-bottom: 14px;
          }
          .navbar-default .navbar-nav .open .dropdown-menu>li>a, .navbar-default .navbar-nav .open .dropdown-menu>li>a:focus, .navbar-default .navbar-nav .open .dropdown-menu>li>a:hover {
            color: white;
          }
          .navbar-default .navbar-toggle {
            border: 0;
          }
          .navbar-default .navbar-toggle:focus, .navbar-default .navbar-toggle:hover {
            background-color: transparent;
          }
          .navbar-default .navbar-toggle .icon-bar {
            background-color: white;
          }
          .fa-lg {
            height: 20px;
            line-height: 20px;
          }
          .navbar-default .navbar-collapse, .navbar-default .navbar-form {
            border-color: #323442;
          }

          .panel-default {
            background-color: #242635;
            color: white;
            border-color: #323442;
          }
          .panel-default input[type=text], .panel-default input[type=password] {
            background-color: #323442;
            border: 1px solid #4c4d58;
            color: white;
          }


          body {
            background-color: #1C1E2D;
            color: #7e8190;
          }
          .usage {
            padding: 0 2rem 2rem;
            box-sizing: border-box;
          }
          .usage div {
            padding: 0.2rem;
            box-sizing: border-box;
          }
          .usage-headings {
            font-size: 1.8rem;
            border-bottom: 1px solid #323442;
            margin: 0 0 1rem;
          }
          .usage-headings div {
            padding: 0.5rem 0.1rem;
          }
          .usage > div {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            text-align: left;
          }
          .usage-form {
            padding: 0 2rem;
            box-sizing: border-box;
          }
          .usage-form form {
            display: flex;
            align-items: center;
          }
          .usage-form label {
            margin: 0 1rem 0 0;
          }
          .usage-form form > div {
            margin: 0 2rem 0 0;
          }
          .usage-form form > .dates {
            display: flex;
            align-items: center;
          }
          .usage-form form > .dates div:first-child {
            margin: 0 2rem 0 0;
          }
          h2 {
            margin: 0;
            padding: 0 0 20px;
            border-bottom: 1px solid #323442;
          }
          input[type=submit] {
            background-color: #323442;
            border: 1px solid #4c4d58;
            padding: 0.2rem 2rem;
            border-radius: 0.5rem;
          }
          .usage-form select {
            background-color: #323442;
            border: 1px solid #4c4d58;
            color: #7e8190;
            padding: 0.2rem 2rem 0.2rem 1rem;
            border-radius: 0.5rem;
            -webkit-appearance: none;
            background-image: url('arrow.svg');
            background-size: 8px;
            background-repeat: no-repeat;
            background-position: right 6px center;
          }
          .usage .seperator {
            width: 100%;
            height: 1px;
            padding: 0;
            display: block;
            background-color: #323442;
            margin: 10px 0 20px;
          }
          @media screen and (max-width: 800px) {
            .usage-line span {
              display: none;
            }
            .usage-headings {
              font-size: 1.4rem;
              font-weight: bold;
            }
            .usage-form form > .dates {
              flex-direction: column;
              align-items: flex-start;
            }
            .usage-form form > .dates div:first-child {
              margin: 0 0 0.5rem 0;
            }
            .usage-form .dates label {
              width: 34px;
            }
            .usage-form form > div {
              margin: 0 1.5rem 0 0;
            }
            input[type=submit] {
              padding: 0.2rem 1.2rem;
            }
            .usage-headings div:first-child, .usage-line div:first-child {
              width: 100px;
            }
            .navbar-collapse {
              padding-right: 0;
              padding-left: 0;
            }
            .navbar-nav {
              margin: 7.5px 0;
            }
            .nav>li>a {
              box-sizing: border-box;
            }
          }
        </style>

    </body>
</html>

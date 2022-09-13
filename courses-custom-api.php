<?php
/**
* Plugin Name: Courses Custom Api
* Plugin URI: portl.com
* Description: this is helper for api rest
* Author: Hosni Colina
* Author URI: portl.com
* Version: 1.0.0
*/

defined( 'ABSPATH' ) || die( "Can't access directly" );

define('COURSE_API_PATH', plugin_dir_path(__FILE__));


require_once(COURSE_API_PATH . '/src/Api/CourseApi.php');
require_once(COURSE_API_PATH . '/src/Api/LessonApi.php');
require_once(COURSE_API_PATH . '/vendor/autoload.php');

new App\Api\Course();

App\Api\Lesson::instance();
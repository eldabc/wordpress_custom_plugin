<?php

namespace App\Api;

use Error;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;

class Course extends WP_REST_Controller
{
    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'course-api/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'course';

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'), 10);
    }


    public function register_routes()
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'create_item'),
                'permission_callback' => array($this, 'create_item_permissions_check'),
                'args' =>  $this->get_collections_params(),
            )
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<course_id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array($this, 'update_item'),
                'permission_callback' => array($this, 'create_item_permissions_check'),
                'args' =>  $this->get_collections_params(),
            )
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/course-categories/', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_categories'),
                'permission_callback' => array($this, 'get_item_permissions_check')
            )
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/course-tags/', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_tags'),
                'permission_callback' => array($this, 'get_item_permissions_check')
            )
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base . '/sections' . '/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array($this, 'update_sections'),
                'permission_callback' => array($this, 'create_item_permissions_check')
            )
        ));
        register_rest_route($this->namespace, '/' . $this->rest_base . '/sections' . '/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_sections'),
                'permission_callback' => array($this, 'get_item_permissions_check')
            )
        ));
    }


    public function create_item_permissions_check($request)
    {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return new WP_REST_Response(['message' => 'user not logged in']);
        }

        $is_vendor_disabled = get_user_meta($user_id, "_disable_vendor", true);

        if (!wcfm_is_vendor($user_id) && !$is_vendor_disabled) {
            return new WP_REST_Response(['message' => 'does not have the necessary permissions to do this.']);
        }
        return true;
    }

    public function get_item_permissions_check($request)
    {
        return true;
    }

    public function get_categories($request)
    {
        $taxs = $this->get_taxonomies('ld_course_category');
        return new WP_REST_Response($taxs, 200);
    }

    public function get_tags($request)
    {
        $taxs = $this->get_taxonomies('ld_course_tag');
        return new WP_REST_Response($taxs, 200);
    }

    public function update_sections($request)
    {
        $params    = $request->get_params();
        $course_id = $params['id'];

        $sections = wp_slash(wp_json_encode(array_values($params['sections']), JSON_UNESCAPED_UNICODE));

        update_post_meta($course_id, 'course_sections', $sections);

        return new WP_REST_Response($this->get_sections_data($course_id), 200);
    }

    public function get_sections($request)
    {
        $params    = $request->get_params();
        $course_id = $params['id'];
        # code...
        return new WP_REST_Response($this->learndash_get_course_data_builder($course_id), 200);
    }


    /**
     * Gets the course data for the course builder.
     *
     * @since 3.4.0
     *
     * @param array $data The data passed down to the front-end.
     *
     * @return array The data passed down to the front-end.
     */
    function learndash_get_course_data_builder($course_id)
    {

        $data = [];
        $output_lessons = array();
        $sections       = array();

        // Get a list of lessons to loop.
        $lessons        = learndash_course_get_lessons(
            $course_id,
            array(
                'return_type' => 'WP_Post',
                'per_page'    => 0,
            )
        );
        $output_lessons = array();

        if ((is_array($lessons)) && (!empty($lessons))) {
            // Loop course's lessons.
            foreach ($lessons as $lesson_post) {
                if (!is_a($lesson_post, 'WP_Post')) {
                    continue;
                }

                // Output lesson with child tree.
                $output_lessons[] = array(
                    'ID'            => $lesson_post->ID,
                    'expanded'      => false,
                    'post_title'    => $lesson_post->post_title,
                    'post_status'   => learndash_get_step_post_status_slug($lesson_post),
                    'type'          => $lesson_post->post_type,
                    'url'           => learndash_get_step_permalink($lesson_post->ID, $course_id),
                    'edit_link'     => get_edit_post_link($lesson_post->ID, ''),
                );
            }
        }

        // Merge sections at Outline.
        $sections_raw = get_post_meta($course_id, 'course_sections', true);
        $sections     = !empty($sections_raw) ? json_decode($sections_raw) : array();

        if ((is_array($sections)) && (!empty($sections))) {
            foreach ($sections as $section) {
                if (!is_object($section)) {
                    continue;
                }

                if ((!property_exists($section, 'ID')) || (empty($section->ID))) {
                    continue;
                }

                if (!property_exists($section, 'order')) {
                    continue;
                }

                if ((!property_exists($section, 'post_title')) || (empty($section->post_title))) {
                    continue;
                }

                if ((!property_exists($section, 'type')) || (empty($section->type))) {
                    continue;
                }

                array_splice($output_lessons, (int) $section->order, 0, array($section));
            }
        }


        // Output data.
        $data['lessons'] = $output_lessons;
        $data['sections'] =  $sections;

        return $data;
    }

    /**
     * Get sections data.
     *
     * @since 3.0.0
     *
     * @param int $course_id The course ID.
     *
     * @return object
     */
    public function get_sections_data($course_id)
    {
        $sections = get_post_meta($course_id, 'course_sections', true);

        return $sections;
    }

    public function create_item($request)
    {
        $params = $request->get_params();
        $authorization = $request->get_header("authorization");

        $user_id = get_current_user_id();

        if (empty($params['content'])) {
        }
        if (empty($authorization)) {
            return new WP_REST_Response(["message" => "You do not have permission to"], 403);
        }

        $course_content = [
            'title' => $params['title'],
            'content' => $params['description'],
            'progression_disabled' => $params['progression_disabled'],
            'price_type' => 'closed',
            'price_type_closed_price' => !empty($params['price']) ?  $params['price'] : 0,
            'ld_course_category' => $params['category'],
            'author' => $user_id,
            'status' => !empty($params['status']) ? $params['status'] : 'publish',
            'disable_content_table' =>  $params['disable_content_table']
        ];


        if (isset($params['featured_media']) && !empty($params['featured_media'])) {
            $course_content['featured_media'] = $params['featured_media'];
        }

        try {

            $course_url = get_rest_url(null, '/ldlms/v2/sfwd-courses');

            $api_response = wp_remote_post($course_url, [
                'sslverify' => false,
                "body" => wp_json_encode($course_content),
                "headers" => [
                    "Content-Type" => "application/json",
                    "Authorization" => $authorization,
                ],
            ]);

            $status_code = wp_remote_retrieve_response_code($api_response);

            if (403 === $status_code || $status_code === 500) {
                return new WP_REST_Response(['message' => 'error'], 403);
            }

            $api_body = wp_remote_retrieve_body($api_response);

            if (empty($api_body)) {
                return [
                    "status" => false,
                    "code" => "error_creating_data_api",
                    "message" => $api_response
                ];
            }

            $apiBody   = json_decode($api_body);

            $course_id = $apiBody->id;

            if (
                isset($params['course_cover'])
                && !empty($params['course_cover'])
            ) {
                $course_cover = update_post_meta($course_id, 'sfwd-courses_course-cover-image_thumbnail_id', $params['course_cover']);
            }
            if (
                isset($params['course_video'])
                && !empty($params['course_video'])
            ) {
                $course_video = update_post_meta($course_id, '_buddyboss_lms_course_video', $params['course_video']);
            }
            if (
                isset($params['short_description'])
                && !empty($params['short_description'])
            ) {
                $short_description = array(
                    'ID'           => $course_id,
                    'post_excerpt' => $params['short_description'],
                );
                wp_update_post($short_description);
            }

            return new WP_REST_Response($apiBody);
        } catch (Error $e) {
            return new WP_REST_Response($e->getMessage(), 500);
        }
    }

    public function update_item($request)
    {
        $params = $request->get_params();
        $authorization = $request->get_header("authorization");

        $user_id = get_current_user_id();

        if (empty($params['content'])) {
        }
        if (empty($authorization)) {
            return new WP_REST_Response(["message" => "You do not have permission to"], 403);
        }

        $course_content = [
            'title' => $params['title'],
            'content' => $params['description'],
            'progression_disabled' => $params['progression_disabled'],
            'price_type' => 'closed',
            'price_type_closed_price' => !empty($params['price']) ?  $params['price'] : 0,
            'ld_course_category' => $params['category'],
            'author' => $user_id,
            'status' => !empty($params['status']) ? $params['status'] : 'publish',
            'disable_content_table' =>  $params['disable_content_table'],
        ];

        if (isset($params['featured_media']) && !empty($params['featured_media'])) {
            $course_content['featured_media'] = $params['featured_media'];
        }

        try {

            $course_url = get_rest_url(null, '/ldlms/v2/sfwd-courses/' . $params["course_id"]);

            $api_response = wp_remote_post($course_url, [
                'sslverify' => false,
                "body" => wp_json_encode($course_content),
                "headers" => [
                    "Content-Type" => "application/json",
                    "Authorization" => $authorization,
                ],
            ]);

            $status_code = wp_remote_retrieve_response_code($api_response);

            if (403 === $status_code || $status_code === 500) {
                return new WP_REST_Response(['message' => 'You do not have permission to'], 403);
            }


            $api_body = wp_remote_retrieve_body($api_response);

            if (empty($api_body)) {
                return [
                    "status" => false,
                    "code" => "error_creating_data_api",
                    "message" => $api_response
                ];
            }

            $apiBody   = json_decode($api_body);

            $course_id = $apiBody->id;


            if (
                isset($params['course_cover'])
                && !empty($params['course_cover'])
            ) {
                $course_cover = update_post_meta($course_id, 'sfwd-courses_course-cover-image_thumbnail_id', $params['course_cover']);
            }
            if (
                isset($params['course_video'])
                && !empty($params['course_video'])
            ) {
                $course_video = update_post_meta($course_id, '_buddyboss_lms_course_video', $params['course_video']);
            }
            if (
                isset($params['short_description'])
                && !empty($params['short_description'])
            ) {
                $short_description = array(
                    'ID'           => $course_id,
                    'post_excerpt' => $params['short_description'],
                );
                wp_update_post($short_description);
            }

            return new WP_REST_Response($apiBody);
        } catch (Error $e) {
            return new WP_REST_Response($e->getMessage(), 500);
        }
    }

    public function get_collections_params()
    {
        return array(
            'course_id' => array(
                'required'    => false,
                'type'        => 'string',
                'description' => __('id of the course to which the extras are to be added.', 'portl'),
            ),
            'title' => array(
                'required'    => true,
                'type'        => 'string',
                'description' => __('The title for the object.

                ', 'portl'),
            ),
            'description' => array(
                'required'    => true,
                'type'        => 'string',
                'description' => __('The content for the object.', 'portl'),
            ),
            'progression_disabled' => array(
                'required'    => false,
                'type'        => 'boolean',
                'description' => __('Course Progression Disabled', 'portl'),
            ),
            'price' => array(
                'required'    => true,
                'type'        => 'string',
                'description' => __('Course Price for the object', 'portl'),
            ),
            'status' => array(
                'required'    => false,
                'type'        => 'string',
                'description' => __('A named status for the object. publish, future, draft, pending, private, graded, not_graded', 'portl'),
            ),
            // 'featured_media' => array(
            //     'required'    => false,
            //     'type'        => 'string',
            //     'description' => __('The ID of the featured media for the object.', 'portl'),
            // ),
            'category' => array(
                'required'    => true,
                'description' => __('The terms assigned to the object in the category taxonomy.', 'portl'),
            ),
            // 'tag' => array(
            //     'required'    => true,
            //     'description' => __('The terms assigned to the object in the post_tag taxonomy.', 'portl'),
            // ),
            'course_cover' => array(
                'required'    => false,
                'description' => __('Cover id', 'portl'),
            ),
            'course_video' => array(
                'required' => false,
                'type' => 'string',
                'description' => __('
                Enter preview video URL for the course. The video will be added on single course price box.', 'portl')
            ),
            'short_description' => array(
                'required'    => false,
                'type'        => 'string',
                'description' => __('This is a short description', 'portl'),
            ),
            'disable_content_table' => array(
                'required' => false,
                'type' => 'boolean',
                'description' => __('Disable Course Content Table', 'portl'),
            )
        );
    }

    public function get_taxonomies($taxonomy)
    {
        $categories = get_terms([
            "taxonomy" => $taxonomy,
            "hide_empty" => false,
        ]);

        if (empty($categories)) {
            return [];
        }

        $cats = [];

        for ($i = 0; $i < count($categories); $i++) {
            $cats[] = [
                "label" => $categories[$i]->name,
                "value" => $categories[$i]->term_taxonomy_id,
                "slug" => $categories[$i]->slug,
            ];
        }

        return $cats;
    }
}

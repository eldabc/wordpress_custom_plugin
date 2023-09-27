<?php

namespace App\Api;

use Error;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use WC_Product_Course;
use LearnDash_Settings_Section;
use WP_Query;

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

    /**
	 * Course post type.
	 *
	 * @var string $post_type
	 */
	protected $post_type = 'sfwd-courses';

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

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<course_id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array($this, 'delete_item'),
                'permission_callback' => array($this, 'create_item_permissions_check')
            )
        ));

        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_item_permissions_check')
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
            'price_type_closed_price' => !empty($params['price']) ? strval($params['price'])  : "0",
            'ld_course_category' => $params['category'],
            'author' => $user_id,
            'status' => !empty($params['status']) ? $params['status'] : 'publish',
            'disable_content_table' =>  $params['disable_content_table']
        ];


        if (isset($params['featured_media']) && !empty($params['featured_media'])) {
            $course_content['featured_media'] = $params['featured_media'];
        }

        try {
            $course_id = $this->create_course($course_content, $params);
            $this->create_product($course_content, $course_id, $user_id);
            return new WP_REST_Response(["id" => $course_id]);
        } catch (Error $e) {
            return new WP_REST_Response($e->getMessage(), 500);
        }
    }

    public function create_product($course, $course_id, $user_id, $product = 0)
    {
        $product = new WC_Product_Course($product);

        $product->set_name($course['title']);
        $product->set_slug(sanitize_title($course['title']));
        $product->set_regular_price($course['price_type_closed_price']);
        $product->set_short_description($course['content']);
        $product_id = $product->save();

        update_post_meta($product_id, '_related_course', [$course_id]);
        update_post_meta($product_id, '_wcfm_product_author', $user_id);
        update_post_meta($course_id, 'product_id', $product_id);
    }

    public function delete_item($request)
    {

        $params = $request->get_params();
        $course_id = $params["course_id"];
        $user_id = get_current_user_id();

        $posts = get_post($course_id);

        if (!$posts) {
            return new WP_REST_Response([
                "status" => false,
                "message" => 'courses no found.',
            ], 404);
        }

        $author_id = $posts->post_author;

        if ((int) $author_id !== $user_id) {
            return new WP_REST_Response([
                "status" => false,
                "message" => 'is not authorized to do so.',
            ], 403);
        }

        try {
            $product_id = get_post_meta($course_id, 'product_id', true);
            wp_delete_post($product_id, true);
            $result = wp_delete_post($course_id, true);
            if ($result) {
                return new WP_REST_Response([
                    "status" => true,
                    "id" => $result->ID,
                ], 200);
            } else {
                return new WP_REST_Response([
                    "status" => false,
                    "message" => "Error deleting message",
                ], 200);
            }
        } catch (Error $e) {
            return new WP_REST_Response([
                "status" => false,
                "message" => $e->getMessage(),
            ], 500);
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
            'price_type_closed_price' => !empty($params['price']) ? strval($params['price']) : "0",
            'ld_course_category' => $params['category'],
            'author' => $user_id,
            'status' => !empty($params['status']) ? $params['status'] : 'publish',
            'disable_content_table' =>  $params['disable_content_table'],
        ];

        if (isset($params['featured_media']) && !empty($params['featured_media'])) {
            $course_content['featured_media'] = $params['featured_media'];
        }

        try {
            $course_id = $this->create_course($course_content, $params);
            $product_id = get_post_meta($course_id, 'product_id', true);
            $this->create_product($course_content, $course_id, $user_id, $product_id);

            return new WP_REST_Response(['id' => $course_id]);
        } catch (Error $e) {
            return new WP_REST_Response($e->getMessage(), 500);
        }
    }

    private function create_course($course_data, $params)
    {
        $course_post = array(
            'post_title'   => sanitize_text_field($course_data['title']),
            'post_content' => sanitize_text_field($course_data['content']),
            'post_type'    => learndash_get_post_type_slug('course'),
            'post_status'  => 'publish',
        );

        if (isset($params["course_id"]) && !empty($params["course_id"])) {
            $course_post['ID'] = $params["course_id"];
        }

        $course_id = wp_insert_post($course_post);

        learndash_update_setting($course_id, 'course_price_type', sanitize_text_field($course_data['price_type']));

        learndash_update_setting($course_id, 'course_disable_lesson_progression', sanitize_text_field($course_data['progression_disabled']));

        $course_price = $course_data['price_type_closed_price'];


        learndash_update_setting($course_id, 'course_price', sanitize_text_field($course_price));

        if (
            isset($params['course_cover'])
            && !empty($params['course_cover'])
        ) {
            update_post_meta($course_id, 'sfwd-courses_course-cover-image_thumbnail_id', $params['course_cover']);
        }

        if (
            isset($params['course_video'])
            && !empty($params['course_video'])
        ) {
            update_post_meta($course_id, '_buddyboss_lms_course_video', $params['course_video']);
        }

        if (
            isset($params['featured_media'])
            && !empty($params['featured_media'])
        ) {
            update_post_meta($course_id, '_thumbnail_id', $params['featured_media']);
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

        if (isset($params["category"]) && !empty($params["category"])) {
            wp_set_object_terms(
                $course_id,
                intval($params["category"]),
                'ld_course_category'
            );
        }

        return $course_id;
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

    /**
	 * Retrieve Courses.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response
	 * @since          0.1.0
	 *
	 * @api            {GET} /wp-json/buddyboss-app/learndash/v1/courses Get LearnDash Courses
	 * @apiName        GetLDCourses
	 * @apiGroup       LD Courses
	 * @apiDescription Retrieve Courses
	 * @apiVersion     1.0.0
	 * @apiPermission  LoggedInUser
	 * @apiParam {Number} [page] Current page of the collection.
	 * @apiParam {Number} [per_page=10] Maximum number of items to be returned in result set.
	 * @apiParam {String} [search] Limit results to those matching a string.
	 * @apiParam {Array} [exclude] Ensure result set excludes specific IDs.
	 * @apiParam {Array} [include] Ensure result set includes specific IDs.
	 * @apiParam {String} [after]  Limit results to those published after a given ISO8601 compliant date.
	 * @apiParam {String} [before] Limit results to those published before a given ISO8601 compliant date.
	 * @apiParam {Array} [author] Limit results to those assigned to specific authors.
	 * @apiParam {Array} [author_exclude] Ensure results to excludes those assigned to specific authors.
	 * @apiParam {String=asc,desc} [order=desc] Sort result set by given order.
	 * @apiParam {String=date,id,title,menu_order} [orderby=date] Sort result set by given field.
	 * @apiParam {Array} [categories] Limit results to those assigned to specific categories.
	 * @apiParam {Number=0,1} [mycourses] Limit results to current user courses.
	 */
	public function get_items( $request ) {
		$user_id    = get_current_user_id();
		$registered = $this->get_collection_params();

		/**
		 * Filter the the request.
		 *
		 * @param WP_REST_Request $request The request sent to the API.
		 *
		 * @since 0.1.0
		 */
		$request = apply_filters( 'bbapp_ld_get_courses_request', $request );

		/**
		 * This array defines mappings between public API query parameters whose
		 * values are accepted as-passed, and their internal WP_Query parameter
		 * name equivalents (some are the same). Only values which are also
		 * present in $registered will be set.
		 */
		$parameter_mappings = array(
			'author'         => 'author__in',
			'author_exclude' => 'author__not_in',
			'exclude'        => 'post__not_in',
			'include'        => 'post__in',
			'offset'         => 'offset',
			'order'          => 'order',
			'orderby'        => 'orderby',
			'page'           => 'paged',
			'parent'         => 'post_parent__in',
			'parent_exclude' => 'post_parent__not_in',
			'search'         => 's',
			'slug'           => 'post_name__in',
			'status'         => 'post_status',
			'mycourses'      => 'mycourses',
			'group_id'       => 'group_id',
			'per_page'       => 'posts_per_page',
		);

		/**
		 * For each known parameter which is both registered and present in the request,
		 * set the parameter's value on the query $args.
		 */
		foreach ( $parameter_mappings as $api_param => $wp_param ) {
			if ( isset( $registered[ $api_param ], $request[ $api_param ] ) ) {
				$args[ $wp_param ] = $request[ $api_param ];
			} elseif ( isset( $registered[ $api_param ]['default'] ) ) {
				$args[ $wp_param ] = $registered[ $api_param ]['default'];
			}
		}
		
		// Check for & assign any parameters which require special handling or setting.
		$args['date_query'] = array();
		
		// Set before into date query. Date query must be specified as an array of an array.
		if ( isset( $registered['before'], $request['before'] ) ) {
			$args['date_query'][0]['before'] = $request['before'];
		}

		// Set after into date query. Date query must be specified as an array of an array.
		if ( isset( $registered['after'], $request['after'] ) ) {
			$args['date_query'][0]['after'] = $request['after'];
		}

		$args     = $this->prepare_items_query( $args, $request );
		$relation = ( ! empty( $request['tax_relation'] ) ) ? $request['tax_relation'] : '';

		if ( ! empty( $relation ) ) {
			$args['tax_query'] = array( 'relation' => $relation ); //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( isset( $request['categories'] ) ) {
			if ( ! is_array( $request['categories'] ) ) {
				$request['categories'] = (array) $request['categories'];
			}

			if ( in_array( 0, $request['categories'], true ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'ld_course_category',
					'operator' => 'NOT EXISTS',
				);
			}

			$request['categories'] = array_filter( $request['categories'] );

			if ( ! empty( $request['categories'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy'         => 'ld_course_category',
					'field'            => 'term_id',
					'terms'            => $request['categories'],
					'include_children' => false,
				);
			}
		}

		if ( ! empty( $request['categories_exclude'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy'         => 'ld_course_category',
				'field'            => 'term_id',
				'terms'            => $request['categories_exclude'],
				'include_children' => false,
				'operator'         => 'NOT IN',
			);
		}

		if ( isset( $request['tags'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy'         => 'ld_course_tag',
				'field'            => 'term_id',
				'terms'            => $request['tags'],
				'include_children' => false,
			);
		}

		if ( ! empty( $request['tags_exclude'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy'         => 'ld_course_tag',
				'field'            => 'term_id',
				'terms'            => $request['tags_exclude'],
				'include_children' => false,
				'operator'         => 'NOT IN',
			);
		}

		if ( isset( $args['mycourses'] ) && $args['mycourses'] ) {
			$mycourse_ids = ld_get_mycourses( $user_id, array() );

			if ( ! empty( $mycourse_ids ) && ! is_wp_error( $mycourse_ids ) ) {
				$args['post__in'] = ! empty( $args['post__in'] ) ? array_intersect( $mycourse_ids, $args['post__in'] ) : $mycourse_ids;
			}

			/*
			 * If we intersected, but there are no post ids in common,
			 * WP_Query won't return "no posts" for post__in = array()
			 * so we have to fake it a bit.
			*/
			if ( ! $args['post__in'] ) {
				$args['post__in'] = array( 0 );
			}

			unset( $args['mycourses'] );
		}

		if ( isset( $args['group_id'] ) && ! empty( $args['group_id'] ) ) {
			$course_ids = array();

			if ( function_exists( 'bp_ld_sync' ) ) {
				$group_id   = bp_ld_sync( 'buddypress' )->helpers->getLearndashGroupId( $args['group_id'] );
				$course_ids = learndash_group_enrolled_courses( $group_id );
			}

			$args['post__in'] = ! empty( $course_ids ) ? $course_ids : array( 0 );

			unset( $args['group_id'] );
		}

		/**
		 * Filter the query arguments for the request.
		 *
		 * @param array           $args    Key value array of query var to query value.
		 * @param WP_REST_Request $request The request sent to the API.
		 *
		 * @since 0.1.0
		 */
		$args = apply_filters( 'bbapp_ld_get_courses_args', $args, $request );

		$args['post_type'] = $this->post_type;
		add_filter( 'posts_distinct', array( $this, 'bbapp_posts_distinct' ), 10, 2 );
		add_filter( 'posts_join', array( $this, 'bbapp_posts_join' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'bbapp_posts_where' ), 10, 1 );
		$posts_query            = new WP_Query();
		$courses['posts']       = $posts_query->query( $args );
		$courses['total_posts'] = $posts_query->found_posts;

		// Todo::dips Check again this code needed or not.
		if ( $courses['total_posts'] < 1 ) {
			// Out-of-bounds, run the query again without LIMIT for total count.
			unset( $args['paged'] );
			$count_query = new WP_Query();
			$count_query->query( $args );
			$courses['total_posts'] = $count_query->found_posts;
		}

		remove_filter( 'posts_where', array( $this, 'bbapp_posts_where' ), 10 );
		remove_filter( 'posts_join', array( $this, 'bbapp_posts_join' ), 10 );
		remove_filter( 'posts_distinct', array( $this, 'bbapp_posts_distinct' ), 10 );

		/**
		 * Fires list of Courses is fetched via Query.
		 *
		 * @param array            $courses Fetched courses.
		 * @param WP_REST_Response $args    Query arguments.
		 * @param WP_REST_Request  $request The request sent to the API.
		 *
		 * @since 0.1.0
		 */
		$courses = apply_filters( 'bbapp_ld_get_courses', $courses, $args, $request );

		$retval = array();

		foreach ( $courses['posts'] as $couese ) {
			if ( ! $this->check_read_permission( $couese ) ) {
				continue;
			}

			$retval[] = $this->prepare_response_for_collection(
				$this->prepare_item_for_response( $couese, $request )
			);
		}

		$response = rest_ensure_response( $retval );
		$response = bbapp_learners_response_add_total_headers( $response, $courses['total_posts'], $args['posts_per_page'] );

		/**
		 * Fires after a list of Courses response is prepared via the REST API.
		 *
		 * @param WP_REST_Response $response The response data.
		 * @param WP_REST_Request  $request  The request sent to the API.
		 *
		 * @since 0.1.0
		 */
		do_action( 'bbapp_ld_course_items_response', $response, $request );

		return $response;
	}

    /**
	 * Prepare items.
	 *
	 * @param array $prepared_args Prepare item parameters.
	 * @param null  $request       Request parameters.
	 *
	 * @return array
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ) {
		$query_args = array();

		foreach ( $prepared_args as $key => $value ) {
			/**
			 * Filters the query_vars used in get_items() for the constructed query.
			 *
			 * The dynamic portion of the hook name, `$key`, refers to the query_var key.
			 *
			 * @param string $value The query_var value.
			 *
			 * @since 4.7.0
			 */
			$query_args[ $key ] = apply_filters( "rest_query_var-{$key}", $value ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}

		$query_args['ignore_sticky_posts'] = true;

		// Map to proper WP_Query orderby param.
		if ( isset( $query_args['orderby'] ) && isset( $request['orderby'] ) ) {
			$orderby_mappings = array(
				'id'            => 'ID',
				'include'       => 'post__in',
				'slug'          => 'post_name',
				'include_slugs' => 'post_name__in',
			);

			if ( isset( $orderby_mappings[ $request['orderby'] ] ) ) {
				$query_args['orderby'] = $orderby_mappings[ $request['orderby'] ];
			}

			if ( is_user_logged_in() ) {
				if ( 'my_progress' === $query_args['orderby'] ) {
					$user_id                  = get_current_user_id();
					$this->my_course_progress = $this->get_courses_progress( $user_id, $query_args['order'] );
					$query_args['order']      = 'desc';
					add_filter( 'posts_clauses', array( $this, 'alter_query_parts_for_my_progress' ), 10, 2 );
				}
			}
		}

		return $query_args;
	}

    /**
	 * Get Open Free and user enrolled courses.
	 *
	 * @param string $where Where clause.
	 *
	 * @return mixed|string
	 */
	public function bbapp_posts_where( $where ) {
		global $wpdb;

		$settings        = \BuddyBossApp\ManageApp::instance()->get_app_settings();
		$where_condition = '';

		if ( is_user_logged_in() ) {
			remove_filter( 'posts_where', array( $this, 'bbapp_posts_where' ), 10 );
			remove_filter( 'posts_join', array( $this, 'bbapp_posts_join' ), 10 );
			remove_filter( 'posts_distinct', array( $this, 'bbapp_posts_distinct' ), 10 );
			$mycourse_ids = ld_get_mycourses( get_current_user_id(), array() );
			add_filter( 'posts_distinct', array( $this, 'bbapp_posts_distinct' ), 10, 2 );
			add_filter( 'posts_join', array( $this, 'bbapp_posts_join' ), 10, 2 );
			add_filter( 'posts_where', array( $this, 'bbapp_posts_where' ), 10, 1 );

			if ( ! empty( $mycourse_ids ) ) {
				$post__in        = implode( ',', array_unique( array_map( 'absint', $mycourse_ids ) ) );
				$where_condition = " AND post_id NOT IN($post__in)";
			}
		}

		$hide_in_app_query = " AND {$wpdb->posts}.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta}  WHERE meta_key='_hide_in_app' AND meta_value = 'yes' $where_condition ) ";

		if ( isset( $settings['learndash_reader_app_compatibility'] ) && '1' === (string) $settings['learndash_reader_app_compatibility'] ) {
			$user_enrolled_course_where      = '';
			$free_open_not_hide_course_where = '';

			if ( isset( $post__in ) ) {
				$user_enrolled_course_where = " {$wpdb->posts}.ID IN ($post__in) OR ";
			}

			$free_open_not_hide_course_where .= "( bbpm.meta_key = '_sfwd-courses' AND ( bbpm.meta_value LIKE '%open%' OR bbpm.meta_value LIKE '%free%' ) ) {$hide_in_app_query} ";
			$where                           .= " AND ( {$user_enrolled_course_where} {$free_open_not_hide_course_where} )";
		} else {
			$where .= $hide_in_app_query;
		}

		return $where;
	}

	/**
	 * Get Open Free and user enrolled courses.
	 *
	 * @param string $join  Join clause.
	 * @param string $query Query.
	 *
	 * @return mixed|string
	 */
	public function bbapp_posts_join( $join, $query ) {
		global $wpdb;

		$join .= " INNER JOIN {$wpdb->postmeta} AS bbpm ON ( {$wpdb->posts}.ID = bbpm.post_id ) ";

		return $join;
	}

    /**
	 * Get Open Free and user enrolled courses.
	 *
	 * @param string $distinct Distuinct value.
	 * @param string $query    Query.
	 *
	 * @return mixed|string
	 */
	public function bbapp_posts_distinct( $distinct, $query ) {
		return ' DISTINCT ';
	}

    /**
	 * Check if we can read a post.
	 *
	 * Correctly handles posts with the inherit status.
	 *
	 * @param object $post Post object.
	 *
	 * @return boolean Can we read it?
	 */
	public function check_read_permission( $post ) {
		return true;
	}

    /**
	 * Prepare a single post output for response.
	 *
	 * @param WP_Post         $post    Post object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response $data
	 */
	public function prepare_item_for_response( $post, $request ) {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		setup_postdata( $post );

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$schema  = $this->get_public_item_schema();

		/**
		 * Create Short Content from Content without more link.
		 */
		add_filter( 'excerpt_more', array( $this, 'remove_excerpt_more_link_and_add_dots' ) );
		$short_content = wp_trim_excerpt( '', $post );
		remove_filter( 'excerpt_more', array( $this, 'remove_excerpt_more_link_and_add_dots' ) );

		// Base fields for every post.
		$data = array(
			'id'           => $post->ID,
			'title'        => array(
				'raw'      => $post->post_title,
				'rendered' => get_the_title( $post->ID ),
			),
			'content'      => array(
				'raw'      => bbapp_learners_fix_relative_urls_protocol( $post->post_content ),
				'rendered' => bbapp_learners_fix_relative_urls_protocol( apply_filters( 'the_content', $post->post_content ) ),
				'short'    => bbapp_learners_fix_relative_urls_protocol( $short_content ),
			),
			'date'         => mysql_to_rfc3339( $post->post_date ),
			'date_gmt'     => mysql_to_rfc3339( $post->post_date_gmt ),
			'modified'     => mysql_to_rfc3339( $post->post_modified ),
			'modified_gmt' => mysql_to_rfc3339( $post->post_modified_gmt ),
			'link'         => get_permalink( $post->ID ),
			'slug'         => $post->post_name,
			'author'       => (int) $post->post_author,
			'excerpt'      => array(
				'raw'      => bbapp_learners_fix_relative_urls_protocol( $post->post_excerpt ),
				'rendered' => bbapp_learners_fix_relative_urls_protocol( apply_filters( 'the_excerpt', $post->post_excerpt ) ),
			),
			'menu_order'   => (int) $post->menu_order,
		);

		/**
		 * Feature Media
		 */
		$post->featured_media            = $this->get_feature_media( $post );
		$data['featured_media']          = array();
		$data['featured_media']['small'] = ( is_array( $post->featured_media ) && isset( $post->featured_media['small'] ) ) ? $post->featured_media['small'] : null;
		$data['featured_media']['large'] = ( is_array( $post->featured_media ) && isset( $post->featured_media['large'] ) ) ? $post->featured_media['large'] : null;
		$post->cover_media               = $this->get_cover_media( $post );
		$data['cover_media']             = array();
		$data['cover_media']['small']    = ( is_array( $post->cover_media ) && isset( $post->cover_media['small'] ) ) ? $post->cover_media['small'] : null;
		$data['cover_media']['large']    = ( is_array( $post->cover_media ) && isset( $post->cover_media['large'] ) ) ? $post->cover_media['large'] : null;

		if ( ! empty( $schema['properties']['has_course_access'] ) && in_array( $context, $schema['properties']['has_course_access']['context'], true ) ) {
			$post->has_course_access   = $this->get_has_course_access( $post );
			$data['has_course_access'] = (bool) $post->has_course_access;
		}

		if ( ! empty( $schema['properties']['offline_disabled'] ) && in_array( $context, $schema['properties']['offline_disabled']['context'], true ) ) {
			$post->offline_disabled   = $this->is_offline_disabled( $post );
			$data['offline_disabled'] = (bool) $post->offline_disabled;
		}

		$post->has_content_access = $this->get_has_content_access( $post );

		if ( ! empty( $schema['properties']['has_content_access'] ) && in_array( $context, $schema['properties']['has_content_access']['context'], true ) ) {
			$data['has_content_access'] = (bool) $post->has_content_access;
		}

		if ( ! empty( $schema['properties']['materials'] ) && in_array( $context, $schema['properties']['materials']['context'], true ) ) {
			$post->materials = $this->get_materials( $post );

			if ( ! $post->has_content_access ) {
				$post->materials = '';
			}

			$data['materials'] = $post->materials;
		}

		if ( ! empty( $schema['properties']['purchasable'] ) && in_array( $context, $schema['properties']['purchasable']['context'], true ) ) {
			$post->purchasable   = $this->is_purchasable( $post );
			$data['purchasable'] = (bool) $post->purchasable;
		}

		if ( ! empty( $schema['properties']['price'] ) && in_array( $context, $schema['properties']['price']['context'], true ) ) {
			$post->price = $this->get_price( $post );

			if ( is_array( $post->price ) ) {
				$data['price'] = array(
					'value'    => $post->price['value'],
					'rendered' => $post->price,
					'code'     => $post->price['code'],
				);
			}
		}

		if ( ! empty( $schema['properties']['hide_content_table'] ) && in_array( $context, $schema['properties']['hide_content_table']['context'], true ) ) {
			$post->hide_content_table   = $this->get_hide_content_table( $post );
			$data['hide_content_table'] = (bool) $post->hide_content_table;
		}

		if ( ! empty( $schema['properties']['progression'] ) && in_array( $context, $schema['properties']['progression']['context'], true ) ) {
			$post->progression   = $this->get_progression( $post );
			$data['progression'] = (int) $post->progression;
		}

		if ( ! empty( $schema['properties']['is_closed'] ) && in_array( $context, $schema['properties']['is_closed']['context'], true ) ) {
			$post->is_closed   = $this->is_closed( $post );
			$data['is_closed'] = (bool) $post->is_closed;
		}

		if ( ! empty( $schema['properties']['can_enroll'] ) && in_array( $context, $schema['properties']['can_enroll']['context'], true ) ) {
			$post->can_enroll   = $this->user_can_enroll( $post );
			$data['can_enroll'] = $post->can_enroll;
		}

		if ( ! empty( $schema['properties']['points'] ) && in_array( $context, $schema['properties']['points']['context'], true ) ) {
			$post->points   = $this->get_points( $post );
			$data['points'] = (int) $post->points;
		}

		if ( ! empty( $schema['properties']['duration'] ) && in_array( $context, $schema['properties']['duration']['context'], true ) ) {
			$data['duration']        = array();
			$data['duration']['min'] = (int) ( is_array( $post->duration ) && isset( $post->duration['min'] ) ) ? $post->duration['min'] : 0;
		}

		if ( ! empty( $schema['properties']['categories'] ) && in_array( $context, $schema['properties']['categories']['context'], true ) ) {
			$post->categories   = $this->get_categories( $post );
			$data['categories'] = $post->categories;
		}

		if ( ! empty( $schema['properties']['tags'] ) && in_array( $context, $schema['properties']['tags']['context'], true ) ) {
			$post->tags   = $this->get_tags( $post );
			$data['tags'] = $post->tags;
		}

		if ( ! empty( $schema['properties']['enrolled_members'] ) && in_array( $context, $schema['properties']['enrolled_members']['context'], true ) ) {
			$course_members           = CoursesMembersRest::instance();
			$total_enrolled           = $course_members->get_access_list( $post );
			$data['enrolled_members'] = (int) count( $total_enrolled );
		}

		if ( ! empty( $schema['properties']['certificate'] ) && in_array( $context, $schema['properties']['certificate']['context'], true ) ) {
			$certificate_id        = bbapp_learndash_get_course_meta_setting( $post->ID, 'certificate' );
			$certificate_available = ( ! empty( $certificate_id ) );
			$certificate_link      = null;
			$certificate_name      = '';

			if ( $certificate_available && $this->is_completed( $post ) ) {
				$certificate_link = learndash_get_course_certificate_link( $post->ID, get_current_user_id() );
				$certificate_link = ! $certificate_link ? null : $certificate_link; // nullify.
				$certificate_name = CertificateRest::get_certificate_name( $post->ID );
			}

			$data['certificate'] = array(
				'available' => $certificate_available,
				'link'      => $certificate_link,
				'filename'  => $certificate_name,
			);
		}

		if ( ! empty( $schema['properties']['module'] ) && in_array( $context, $schema['properties']['module']['context'], true ) ) {
			if ( isset( $post->module ) ) {
				$data['module'] = $post->module;
			} else {
				$data['module'] = array();
			}
		}

		if ( ! empty( $schema['properties']['video'] ) && in_array( $context, $schema['properties']['video']['context'], true ) ) {
			if ( ! $post->has_content_access ) {
				$post->video = '';
			}
			$data['video'] = $post->video;
		}

		if ( ! empty( $schema['properties']['group'] ) && in_array( $context, $schema['properties']['group']['context'], true ) ) {
			$data['group'] = (int) $post->group;
		}

		if ( ! empty( $schema['properties']['forum'] ) && in_array( $context, $schema['properties']['forum']['context'], true ) ) {
			$data['forum'] = (int) $post->forum;
		}

		if ( ! empty( $schema['properties']['lessons'] ) && in_array( $context, $schema['properties']['lessons']['context'], true ) ) {
			$post->lessons   = $this->get_lessons( $post );
			$data['lessons'] = $post->lessons;
		}

		if ( ! empty( $schema['properties']['quizzes'] ) && in_array( $context, $schema['properties']['quizzes']['context'], true ) ) {
			$post->quizzes   = $this->get_quizzes( $post );
			$data['quizzes'] = $post->quizzes;
		}

		if ( ! empty( $schema['properties']['completed'] ) && in_array( $context, $schema['properties']['completed']['context'], true ) ) {
			$post->completed   = $this->is_completed( $post );
			$data['completed'] = (bool) $post->completed;
		}

		if ( ! empty( $schema['properties']['quiz_completed'] ) && in_array( $context, $schema['properties']['quiz_completed']['context'], true ) ) {
			$post->quiz_completed   = $this->is_quiz_completed( $post, $post->ID );
			$data['quiz_completed'] = (bool) $post->quiz_completed;
		}

		if ( ! empty( $schema['properties']['show_start'] ) && in_array( $context, $schema['properties']['show_start']['context'], true ) ) {
			$post->show_start   = $this->is_progress_start( $post );
			$data['show_start'] = (bool) $post->show_start;
		}

		if ( ! empty( $schema['properties']['course_status'] ) && in_array( $context, $schema['properties']['course_status']['context'], true ) ) {
			$post->course_status   = $this->get_course_status( $post );
			$data['course_status'] = $post->course_status;
		}

		if ( ! empty( $schema['properties']['error_message'] ) && in_array( $context, $schema['properties']['error_message']['context'], true ) ) {
			$post->error_message = $this->get_error_message( $post );

			if ( ! empty( $post->error_message ) ) {
				$error_code            = $post->error_message->get_error_code();
				$data['error_message'] = array(
					'code'    => $error_code,
					'message' => $post->error_message->get_error_message(),
					'data'    => $post->error_message->get_error_data( $error_code ),
				);
			} else {
				$data['error_message'] = array();
			}
		}

		$data = $this->add_additional_fields_to_object( $data, $request );
		$data = $this->filter_response_by_context( $data, $context );

		// Wrap the data in a response object.
		$response = rest_ensure_response( $data );

		/**
		 * Filters course response.
		 *
		 * @param WP_REST_Response $response Rest response.
		 * @param WP_Post          $post     Post object.
		 * @param WP_REST_Request  $request  Request object.
		 */
		return apply_filters( 'bbapp_ld_rest_prepare_course', $response, $post, $request );
	}

    /**
	 * Remove excerpt More link as it not need and added dots.
	 *
	 * @return string
	 */
	public function remove_excerpt_more_link_and_add_dots() {
		return '...';
	}

    /**
	 * Get the course medias.
	 *
	 * @param WP_Post $post Course post.
	 *
	 * @return array
	 */
	private function get_feature_media( $post ) {
		$return = array(
			'large' => '',
			'small' => '',
		);
		$large  = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'large' );
		$small  = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'medium' );

		if ( isset( $large[0] ) ) {
			$return['large'] = $large[0];
		}
		if ( isset( $small[0] ) ) {
			$return['small'] = $small[0];
		}

		return $return;
	}

    /**
	 * Get the course cover media.
	 *
	 * @param WP_Post $post Course post.
	 *
	 * @return array
	 */
	private function get_cover_media( $post ) {
		$return = array(
			'large' => '',
			'small' => '',
		);

		$course_cover_photo_id = 0;

		/**
		 * BuddyBoss Theme Feature Image Support.
		 */
		if ( class_exists( '\BuddyBossTheme\BuddyBossMultiPostThumbnails' ) ) {
			$course_cover_photo_id = \BuddyBossTheme\BuddyBossMultiPostThumbnails::get_post_thumbnail_id( 'sfwd-courses', 'course-cover-image', $post->ID );
		} elseif ( class_exists( 'BuddyBossApp\Helpers\PostCover' ) ) { // Fallback Feature Image Support.
			$course_cover_photo_id = PostCover::get_post_thumbnail_id(
				'sfwd-courses',
				'course-cover-image',
				$post->ID
			);
		}

		if ( ! empty( $course_cover_photo_id ) ) {
			$large = wp_get_attachment_image_src( $course_cover_photo_id, 'large' );
			$small = wp_get_attachment_image_src( $course_cover_photo_id, 'medium' );

			if ( isset( $large[0] ) ) {
				$return['large'] = $large[0];
			}

			if ( isset( $small[0] ) ) {
				$return['small'] = $small[0];
			}
		}

		return $return;
	}

    /**
	 * Get course content access.
	 *
	 * @param WP_Post $post    Course post.
	 * @param bool    $message Message.
	 *
	 * @return bool
	 */
	private function get_has_content_access( $post, $message = false ) {
		return bbapp_lms_is_content_access( $post, 'prerequities_completed' ) && bbapp_lms_is_content_access( $post, 'points_access' );
	}

	/**
	 * Get the query params for collections of attachments.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['after'] = array(
			'description'       => __( 'Limit response to resources published after a given ISO8601 compliant date.', 'buddyboss-app' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['author'] = array(
			'description'       => __( 'Limit result set to posts assigned to specific authors.', 'buddyboss-app' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['author_exclude'] = array(
			'description'       => __( 'Ensure result set excludes posts assigned to specific authors.', 'buddyboss-app' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['before'] = array(
			'description'       => __( 'Limit response to resources published before a given ISO8601 compliant date.', 'buddyboss-app' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['exclude'] = array(
			'description'       => __( 'Ensure result set excludes specific ids.', 'buddyboss-app' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => 'wp_parse_id_list',
		);
		$params['include'] = array(
			'description'       => __( 'Limit result set to specific ids.', 'buddyboss-app' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => 'wp_parse_id_list',
		);

		$params['offset']            = array(
			'description'       => __( 'Offset the result set by a specific number of items.', 'buddyboss-app' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['order']             = array(
			'description'       => __( 'Order sort attribute ascending or descending.', 'buddyboss-app' ),
			'type'              => 'string',
			'default'           => 'desc',
			'enum'              => array( 'asc', 'desc' ),
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['orderby']           = array(
			'description'       => __( 'Sort collection by object attribute.', 'buddyboss-app' ),
			'type'              => 'string',
			'default'           => 'date',
			'enum'              => array(
				'date',
				'id',
				'include',
				'title',
				'slug',
				'relevance',
				'my_progress',
			),
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['orderby']['enum'][] = 'menu_order';

		$params['parent']         = array(
			'description'       => __( 'Limit result set to those of particular parent IDs.', 'buddyboss-app' ),
			'type'              => 'array',
			'sanitize_callback' => 'wp_parse_id_list',
			'items'             => array( 'type' => 'integer' ),
		);
		$params['parent_exclude'] = array(
			'description'       => __( 'Limit result set to all items except those of a particular parent ID.', 'buddyboss-app' ),
			'type'              => 'array',
			'sanitize_callback' => 'wp_parse_id_list',
			'items'             => array( 'type' => 'integer' ),
		);

		$params['slug']   = array(
			'description'       => __( 'Limit result set to posts with a specific slug.', 'buddyboss-app' ),
			'type'              => 'string',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['filter'] = array(
			'description' => __( 'Use WP Query arguments to modify the response; private query vars require appropriate authorization.', 'buddyboss-app' ),
		);

		$params['categories'] = array(
			'description' => __( 'Limit result set to all items that have the specified term assigned in the category.', 'buddyboss-app' ),
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
		);

		$params['categories_exclude'] = array(
			'description' => __( 'Limit result set to all items that have the specified term assigned in the category.', 'buddyboss-app' ),
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
		);

		$params['tags'] = array(
			'description' => __( 'Limit result set to all items that have the specified term assigned in the tag.', 'buddyboss-app' ),
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
		);

		$params['tags_exclude'] = array(
			'description' => __( 'Limit result set to all items except those that have the specified term assigned in the tag.', 'buddyboss-app' ),
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
		);

		$params['mycourses'] = array(
			'description'       => __( 'Limit response to resources which is taken by current user.', 'buddyboss-app' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['group_id'] = array(
			'description'       => __( 'Limit response to resources that are connected with a group.', 'buddyboss-app' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}
}

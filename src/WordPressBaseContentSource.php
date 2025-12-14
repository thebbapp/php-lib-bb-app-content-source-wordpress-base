<?php

declare(strict_types=1);

namespace BbApp\ContentSource\WordPressBase;

use BbApp\ContentSource\{ContentSourceAbstract, ContentSourceCallbacks};
use UnexpectedValueException;
use WP_Post, WP_Comment, WP_REST_Server, WP_REST_Request, WP_REST_Response;

/**
 * Base WordPress implementation of content source with URL matching and permissions.
 */
abstract class WordPressBaseContentSource extends ContentSourceAbstract
{
	private $allowed_tags = '<a><strong><b><em><i><u><img><code><pre><blockquote><ul><ol><li><table><thead><tbody><tr><td><th><h1><h2><h3><h4><h5><h6>';

	/**
	 * Initializes with WordPress-specific URL matching callback.
	 */
	public function __construct() {
		parent::__construct(new class extends ContentSourceCallbacks {
			public function url_match_checker(string $url): bool {
				$parsed_url = wp_parse_url($url);
				$parsed_home = parse_url(get_home_url());
				$url_host = preg_replace("/^www\./", "", $parsed_url['host']);
				$home_host = preg_replace("/^www\./", "", $parsed_home['host']);
				return isset($parsed_home['host']) && strtolower($home_host) === strtolower($url_host);
			}
		});
	}

	/**
	 * Determine if rendered content contains HTML beyond plain text.
	 */
	public function content_has_html(
		string $raw,
		string $plaintext
	): bool {
		$raw_without_comments = preg_replace('/<!--.*?-->/s', '', $raw);
		$raw_without_non_semantic = strip_tags($raw_without_comments, $this->allowed_tags);

		$raw_stripped = trim(preg_replace('/\s+/', ' ', $raw_without_non_semantic));
		$plain_normalized = trim(preg_replace('/\s+/', ' ', $plaintext));

		return $raw_stripped !== $plain_normalized;
	}

	/**
	 * Convert rendered markup to stripped plaintext.
	 */
	public function content_plaintext(string $rendered): string {
		return trim(wp_strip_all_tags(html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true));
	}

	/**
	 * Prepares post response with rendered content and HTML detection.
	 */
	public function rest_prepare_post(
		WP_REST_Response $response,
		WP_Post $post,
		WP_REST_Request $request
	): WP_REST_Response {
		$data = $this->rest_response_data(
			$response,
			$post->post_content,
			(int) $post->post_author
		);

		$response->set_data($data + [
				'comment_count' => (int) $post->comment_count
			]);

		return $response;
	}

	/**
	 * Prepares comment response with rendered content and HTML detection.
	 */
	public function rest_prepare_comment(
		WP_REST_Response $response,
		WP_Comment $comment,
		WP_REST_Request $request
	): WP_REST_Response {
		$response->set_data($this->rest_response_data(
			$response,
			$comment->comment_content,
			(int) $comment->user_id
		));

		return $response;
	}

	/**
	 * Returns data for REST response.
	 */
	public function rest_response_data(
		WP_REST_Response $response,
		string $content,
		int $user_id
	): array {
		$data = $response->get_data();

		$rendered = $this->get_rendered_content(
			$data['content']['rendered'] ?? $content,
			get_home_url()
		);

		$plaintext = $this->content_plaintext($rendered);
		$has_html = $this->content_has_html($rendered, $plaintext);

		if ($has_html) {
			$has_html = !empty($user_id) && user_can($user_id, 'unfiltered_html');
		}

		$data += compact('has_html');

		if (isset($data['title']['rendered'])) {
			$data['title']['rendered'] = wp_strip_all_tags($this->get_rendered_title($data['title']['rendered']), true);
		}

		$data['content']['rendered'] = $has_html ? $rendered : $plaintext;
		return $data;
	}

	public function get_current_request(): WP_REST_Request {
		$request = new WP_REST_Request(
			$_SERVER['REQUEST_METHOD'] ?? 'GET',
			$_GET['rest_route'] ?? $_SERVER['REQUEST_URI'] ?? ''
		);

		$request->set_query_params(wp_unslash($_GET));
		$request->set_body_params(wp_unslash($_POST));
		$request->set_file_params($_FILES);
		$request->set_headers(wp_unslash($_SERVER));

		return $request;
	}

	/**
	 * Register a permalink field for the given content type.
	 */
	public function register_link_field(string $content_type): void {
		register_rest_field($this->get_entity_types($content_type), 'link', [
			'get_callback' => function (
				$object
			) use
			(
				$content_type
			) {
				return $this->get_link($content_type, (int) $object['id']);
			},

			'schema' => [
				'type' => 'string',
				'context' => ['view', 'edit']
			]
		]);
	}

	/**
	 * Register a capability flag field for the given intent and type.
	 */
	public function register_capability_field(
		string $content_type,
		string $attribute,
		string $intent
	) {
		register_rest_field($this->get_entity_types($content_type), $attribute, [
			'get_callback' => function (
				$post
			) use
			(
				$content_type,
				$intent
			) {
				return $this->current_user_can($intent, $content_type, (int) $post['id']);
			},

			'schema' => [
				'type' => 'boolean',
				'context' => ['view', 'embed']
			]
		]);
	}

	/**
	 * Pre-dispatch hook to hydrate guest identity from request.
	 */
	public function pre_dispatch(
		$response,
		WP_REST_Server $server,
		WP_REST_Request $request
	): ?WP_REST_Response {
		$query = $request->get_query_params();

		if ($request->has_param('guest_id')) {
			bb_app_current_guest_id($request->get_param('guest_id'));
		} else if (!empty($query['guest_id'])) {
			bb_app_current_guest_id($query['guest_id']);
		}

		return $response;
	}

	/**
	 * Post-dispatch hook to inject shared response headers.
	 */
	public function post_dispatch(
		$response,
		WP_REST_Server $server,
		WP_REST_Request $request
	): WP_REST_Response {
		$headers = $response->get_headers() + static::getResponseHeaders();
		$response->set_headers($headers);
		return $response;
	}

	/**
	 * Build response headers describing content source data.
	 */
	public function getResponseHeaders(): array {
		return [
			'Bb-App-Content-Source-Options' => json_encode($this->get_options_data()),
			'Bb-App-Content-Source-Features' => json_encode($this->get_features_data())
		];
	}

	/**
	 * Checks if a user has permission for an intent on specific content.
	 */
	public function user_can(
		int $user_id,
		string $intent,
		string $content_type,
		int $content_id
	): bool {
		$capability = $this->capabilities[$content_type][$intent] ?? null;

		if ($capability === null) {
			throw new UnexpectedValueException();
		}

		return user_can($user_id, $capability, $content_id);
	}

	/**
	 * Checks if current user has permission for an intent on specific content.
	 */
	public function current_user_can(
		string $intent,
		string $content_type,
		int $content_id
	): bool {
		return $this->user_can(get_current_user_id(), $intent, $content_type, $content_id);
	}

	/**
	 * Returns configuration options for the content source.
	 */
	public function get_options_data(): array {
		return [
			'container_id' => $this->id,
			'root_section_id' => $this->get_root_section_id(),
			'root_parent_id' => $this->get_root_parent_id()
		];
	}

	/**
	 * Returns feature flags for the content source.
	 */
	public function get_features_data(): array {
		return [
			'can_users_register' => (bool) get_option('users_can_register')
		];
	}

	/**
	 * Register REST field hooks when bb-app context is present.
	 */
	public function register(): void {
		add_action('rest_request_before_callbacks', function (
			$server,
			$handler,
			WP_REST_Request $request
		) {
			$this->register_capability_field('section', 'user_can_post', 'post');
			$this->register_capability_field('post', 'user_can_comment', 'comment');
			$this->register_capability_field('post', 'user_can_edit', 'edit');
			$this->register_capability_field('comment', 'user_can_edit', 'edit');

			$this->register_link_field('section');
			$this->register_link_field('post');
			$this->register_link_field('comment');
		}, 10, 3);

		add_filter('rest_post_dispatch', [$this, 'post_dispatch'], 20, 3);
	}

	/**
	 * Initialize global REST filters and optional Basic Auth handler.
	 */
	public function init(): void {
		add_filter('rest_pre_dispatch', [$this, 'pre_dispatch'], 20, 3);
	}
}

<?php
/**
 * Custom Post Type "Proposta de Votação" e meta de contagem de votos.
 *
 * MVP/esqueleto: apenas a estrutura de dados (CPT + meta) é implementada
 * aqui. A interface de votação em si (quem pode votar, uma vez por
 * beneficiário, prazo de votação etc.) depende das regras de
 * funcionamento da plataforma, a serem definidas em processo
 * participativo com os beneficiários (Atividade 2.10 do edital).
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Proposta_Votacao {

	const POST_TYPE = 'proposta_votacao';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_post_type' ) );
		add_action( 'init', array( __CLASS__, 'registrar_meta_votos' ) );
	}

	public static function registrar_post_type() {
		$labels = array(
			'name'               => __( 'Propostas de Votação', 'reconectar-core' ),
			'singular_name'      => __( 'Proposta de Votação', 'reconectar-core' ),
			'add_new_item'       => __( 'Adicionar Nova Proposta', 'reconectar-core' ),
			'edit_item'          => __( 'Editar Proposta', 'reconectar-core' ),
			'new_item'           => __( 'Nova Proposta', 'reconectar-core' ),
			'view_item'          => __( 'Ver Proposta', 'reconectar-core' ),
			'search_items'       => __( 'Buscar Propostas', 'reconectar-core' ),
			'not_found'          => __( 'Nenhuma proposta encontrada', 'reconectar-core' ),
			'not_found_in_trash' => __( 'Nenhuma proposta na lixeira', 'reconectar-core' ),
			'menu_name'          => __( 'Propostas de Votação', 'reconectar-core' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => $labels,
				'public'       => true,
				'show_in_menu' => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-groups',
				'supports'     => array( 'title', 'editor', 'excerpt' ),
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'proposta-de-votacao' ),
			)
		);
	}

	public static function registrar_meta_votos() {
		register_post_meta(
			self::POST_TYPE,
			'_reconectar_votos_favor',
			array(
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_reconectar_votos_contra',
			array(
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}

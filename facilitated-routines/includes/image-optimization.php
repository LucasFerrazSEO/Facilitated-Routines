<?php
add_filter( 'wp_get_attachment_image_src', 'rapido_replace_img_src' , 1000, 2 );
add_filter( 'wp_generate_attachment_metadata', 'rapido_handle_attachments', 1000, 2 );
add_filter( 'big_image_size_threshold', 'rapido_handle_big_attachments', 1000 );
add_filter( 'upload_mimes', 'rapido_custom_mime_types' );
add_action( 'wp_loaded', 'rapido_verify_for_image_optimization_libs' );

/**
 * cria versão webp sem metadados a partir do caminho da original
 */
function rapido_create_webp_version( $image_file_path ) {
    if ( ! $image_file_path || ! file_exists( $image_file_path ) ) return;

    // evita processar svg e webp
    $ext = strtolower( pathinfo( $image_file_path, PATHINFO_EXTENSION ) );
    if ( in_array( $ext, array( 'svg', 'webp' ), true ) ) return;

    if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagewebp' ) ) {
        try {
            $blob = file_get_contents( $image_file_path );
            if ( $blob === false ) return;

            $img = imagecreatefromstring( $blob );
            if ( ! $img ) return;

            imagepalettetotruecolor( $img );
            imagealphablending( $img, true );
            imagesavealpha( $img, true );

            // regrava em webp, o gd não carrega exif na saída, logo remove metadados
            imagewebp( $img, $image_file_path . '.webp', 80 );
            imagedestroy( $img );
        } catch ( \Throwable $th ) {
            // silencioso
        }
    } elseif ( class_exists( 'Imagick' ) ) {
        try {
            $image = new Imagick( $image_file_path );
            $image->setImageFormat( 'webp' );
            $image->setOption( 'webp:lossless', 'false' );
            $image->setOption( 'webp:method', '6' );
            $image->setImageCompressionQuality( 80 );
            // remove todos os metadados
            if ( method_exists( $image, 'stripImage' ) ) {
                $image->stripImage();
            }
            $image->writeImage( $image_file_path . '.webp' );
            $image->clear();
        } catch ( \Throwable $th ) {
            // silencioso
        }
    }
}

/**
 * ao gerar metadados, cria webp para original, variações e versão scaled se existir
 */
function rapido_handle_attachments( $metadata, $image_id ) {
    try {
        $image = get_post( $image_id );
        if ( ! $image || ! rapido_should_optimize_image( $image->post_mime_type ) ) return $metadata;

        $original = wp_get_original_image_path( $image_id );
        rapido_create_webp_version( $original );

        // cria webp para cada size
        if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $dir = pathinfo( $original, PATHINFO_DIRNAME );
            foreach ( $metadata['sizes'] as $props ) {
                if ( empty( $props['file'] ) ) continue;
                $path = trailingslashit( $dir ) . $props['file'];
                rapido_create_webp_version( $path );
            }
        }

        // cobre o arquivo -scaled do wp
        $f_arr     = explode( '.', $original );
        $extension = end( $f_arr );
        $scaled    = str_replace( '.' . $extension, "-scaled.$extension", $original );
        if ( file_exists( $scaled ) ) {
            rapido_create_webp_version( $scaled );
        }
    } catch ( \Throwable $th ) {
        // silencioso
    }

    return $metadata;
}

/**
 * define quais tipos podem receber webp
 */
function rapido_should_optimize_image( $type ) {
    $type_arr = explode( '/', (string) $type );
    if ( isset( $type_arr[0], $type_arr[1] ) && $type_arr[0] === 'image' ) {
        $sub = strtolower( $type_arr[1] );
        if ( strpos( $sub, 'jpg' ) !== false || strpos( $sub, 'jpeg' ) !== false || strpos( $sub, 'png' ) !== false ) {
            return true;
        }
    }
    return false;
}

/**
 * troca o src da original pela versão webp quando disponível
 */
function rapido_replace_img_src( $image, $attach_id ) {
    $obj_image = get_post( $attach_id );
    if ( is_admin() || ! $obj_image || ! rapido_should_optimize_image( $obj_image->post_mime_type ) ) {
        return $image;
    }

    $original_img_path   = wp_get_original_image_path( $attach_id );
    $optimized_img_path  = $original_img_path . '.webp';

    if ( ! file_exists( $optimized_img_path ) ) {
        $meta = wp_get_attachment_metadata( $attach_id );
        rapido_handle_attachments( is_array( $meta ) ? $meta : array(), $attach_id );
    }

    // se a url apontar para este domínio, tenta gerar webp para a variação específica usada no html
    if ( is_array( $image ) && ! empty( $image[0] ) ) {
        $blog_url = get_home_url();
        if ( strpos( $image[0], $blog_url ) === 0 ) {
            $gen_path = ABSPATH . ltrim( str_replace( $blog_url, '', $image[0] ), '/' );
            if ( file_exists( $gen_path ) && ! file_exists( $gen_path . '.webp' ) ) {
                rapido_create_webp_version( $gen_path );
            }
        }
    }

    if ( file_exists( $optimized_img_path ) && is_array( $image ) && ! empty( $image[0] ) ) {
        $image[0] = $image[0] . '.webp';
    }
    return $image;
}

function rapido_handle_big_attachments( $threshold ) {
    return $threshold;
}

function rapido_custom_mime_types( $mimes ) {
    $mimes['svg']  = 'image/svg+xml';
    $mimes['webp'] = 'image/webp';
    if ( ! defined( 'ALLOW_UNFILTERED_UPLOADS' ) ) define( 'ALLOW_UNFILTERED_UPLOADS', true );
    return $mimes;
}

function rapido_verify_for_image_optimization_libs() {
    if ( ! function_exists( 'imagecreatefromstring' ) && ! function_exists( 'imagewebp' ) && ! class_exists( 'Imagick' ) ) {
        add_action( 'admin_notices', function () {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><span style='font-size:16px;'>a otimização de imagens não foi habilitada porque o servidor não tem php gd com suporte a webp nem a extensão php imagick, solicite a instalação para aproveitar a redução no tamanho das imagens.</span></p>
            </div>
            <?php
        }, 10, 2 );
    }
}

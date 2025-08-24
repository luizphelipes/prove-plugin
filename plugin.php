<?php
/**
 * Plugin Name: Toast de Compras Recentes do Instagram (Corrigido)
 * Description: Exibe toasts com fotos de perfil e produtos comprados por clientes
 * Version: 1.2
 * Author: Seu Nome
 */

if (!defined('ABSPATH')) exit;

class InstagramPurchaseToasts {
    
    private $cache_timeout = 86400; // 24 horas em segundos
    private $table_name;
    private $avatars_table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'instagram_social_proofs';
        $this->avatars_table_name = $wpdb->prefix . 'instagram_avatars';
        
        register_activation_hook(__FILE__, [$this, 'create_tables']);
        add_action('init', [$this, 'init']);
    }
    
    public function init() {
        // Verificar se está em modo de manutenção
        if (get_option('instagram_toasts_maintenance', false)) {
            return;
        }
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'display_toasts_container']);
        add_action('wp_ajax_get_recent_purchases', [$this, 'get_recent_purchases_ajax']);
        add_action('wp_ajax_nopriv_get_recent_purchases', [$this, 'get_recent_purchases_ajax']);
        
        add_action('wp_ajax_debug_orders', [$this, 'debug_orders_ajax']);
        add_action('wp_ajax_nopriv_debug_orders', [$this, 'debug_orders_ajax']);
        add_action('wp_ajax_force_cache_update', [$this, 'force_cache_update_ajax']);
        add_action('wp_ajax_nopriv_force_cache_update', [$this, 'force_cache_update_ajax']);
        
        // AJAX para marcar toast como exibido
        add_action('wp_ajax_mark_toast_displayed', [$this, 'mark_toast_displayed_ajax']);
        add_action('wp_ajax_nopriv_mark_toast_displayed', [$this, 'mark_toast_displayed_ajax']);

        // Adicionar menu de administração
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        
        // Handlers AJAX para teste
        add_action('wp_ajax_test_toast_display', [$this, 'test_toast_ajax']);
        add_action('wp_ajax_nopriv_test_toast_display', [$this, 'test_toast_ajax']);
        
        // Agendar limpeza diária do cache
        if (!wp_next_scheduled('instagram_toasts_cleanup')) {
            wp_schedule_event(time(), 'daily', 'instagram_toasts_cleanup');
        }
        add_action('instagram_toasts_cleanup', [$this, 'cleanup_old_cache']);
        
        // Processar pedidos em background
        add_action('wp_ajax_process_background_orders', [$this, 'process_background_orders']);
        add_action('wp_ajax_nopriv_process_background_orders', [$this, 'process_background_orders']);
        
        // Agendar processamento em background
        if (!wp_next_scheduled('instagram_process_orders')) {
            wp_schedule_event(time(), 'hourly', 'instagram_process_orders');
        }
        add_action('instagram_process_orders', [$this, 'process_orders_background']);
    }
    
    /**
     * Criar tabelas do banco de dados
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabela para armazenar provas sociais processadas
        $sql_social_proofs = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            instagram_username varchar(100) NOT NULL,
            customer_name varchar(200) NOT NULL,
            product_name varchar(500) NOT NULL,
            product_price decimal(10,2) DEFAULT NULL,
            product_permalink varchar(500) DEFAULT NULL,
            order_date datetime NOT NULL,
            avatar_url varchar(500) DEFAULT NULL,
            avatar_fetched tinyint(1) DEFAULT 0,
            display_count int(11) DEFAULT 0,
            last_displayed datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status enum('pending','ready','displayed','expired') DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY unique_order_product (order_id, product_name(100)),
            KEY idx_instagram_username (instagram_username),
            KEY idx_status (status),
            KEY idx_avatar_fetched (avatar_fetched),
            KEY idx_order_date (order_date)
        ) $charset_collate;";
        
        // Tabela para cache de avatares do Instagram
        $sql_avatars = "CREATE TABLE {$this->avatars_table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            username varchar(100) NOT NULL,
            avatar_url varchar(500) NOT NULL,
            avatar_hd_url varchar(500) DEFAULT NULL,
            fetched_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            fetch_attempts int(11) DEFAULT 1,
            last_error text DEFAULT NULL,
            status enum('valid','expired','error') DEFAULT 'valid',
            PRIMARY KEY (id),
            UNIQUE KEY unique_username (username),
            KEY idx_status (status),
            KEY idx_expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_social_proofs);
        dbDelta($sql_avatars);
        
        $this->log_message('Tabelas do banco de dados criadas/atualizadas com sucesso');
    }
    
    /**
     * AJAX handler para buscar provas sociais prontas (dados locais)
     */
    public function get_recent_purchases_ajax() {
        try {
            // Verificar nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'instagram_toasts_nonce')) {
                wp_send_json_error('Nonce inválido');
                return;
            }
            
            // Buscar provas sociais prontas do banco local
            $social_proofs = $this->get_ready_social_proofs();
            
            $this->log_message('Provas sociais obtidas do banco local: ' . count($social_proofs));
            
            wp_send_json_success($social_proofs);
            
        } catch (Exception $e) {
            $this->log_message('ERRO: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Buscar provas sociais prontas para exibição (dados locais)
     */
    private function get_ready_social_proofs($limit = 10) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                id,
                order_id,
                instagram_username,
                customer_name,
                product_name,
                product_price,
                product_permalink,
                order_date,
                avatar_url,
                display_count,
                last_displayed
            FROM {$this->table_name} 
            WHERE status = 'ready' 
            AND avatar_fetched = 1
            AND avatar_url IS NOT NULL
            ORDER BY 
                CASE 
                    WHEN last_displayed IS NULL THEN 0 
                    ELSE TIMESTAMPDIFF(HOUR, last_displayed, NOW()) 
                END DESC,
                order_date DESC
            LIMIT %d
        ", $limit));
        
        $social_proofs = [];
        foreach ($results as $row) {
            $social_proofs[] = [
                'id' => $row->id,
                'order_id' => $row->order_id,
                'instagram_username' => $row->instagram_username,
                'customer_name' => $row->customer_name,
                'avatar_url' => $row->avatar_url,
                'products' => [[
                    'name' => $row->product_name,
                    'price' => $row->product_price ? number_format((float)$row->product_price, 2, ',', '.') : null,
                    'permalink' => $row->product_permalink ?: '#'
                ]],
                'order_date' => $row->order_date,
                'display_count' => (int)$row->display_count,
                'last_displayed' => $row->last_displayed
            ];
        }
        
        return $social_proofs;
    }
    
    /**
     * Processar pedidos em background e salvar como provas sociais
     */
    public function process_orders_background() {
        try {
            if (!class_exists('WooCommerce')) {
                $this->log_message('WooCommerce não está ativo para processamento em background');
                return;
            }
            
            $this->log_message('Iniciando processamento de pedidos em background');
            
            // Buscar pedidos recentes que ainda não foram processados
            $processed_orders = $this->process_new_orders();
            
            // Buscar avatares pendentes
            $avatars_processed = $this->fetch_pending_avatars();
            
            $this->log_message("Processamento em background concluído. Pedidos: {$processed_orders}, Avatares: {$avatars_processed}");
            
        } catch (Exception $e) {
            $this->log_message('ERRO no processamento em background: ' . $e->getMessage());
        }
    }
    
    /**
     * Processar novos pedidos e salvar como provas sociais
     */
    private function process_new_orders() {
        global $wpdb;
        
        // Buscar pedidos dos últimos 7 dias que ainda não foram processados
        $args = [
            'status' => ['processing', 'completed'],
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_created' => '>' . (time() - (7 * 24 * 60 * 60)),
            'return' => 'objects'
        ];
        
        $orders = wc_get_orders($args);
        $processed_count = 0;
        
        foreach ($orders as $order) {
            try {
                $order_id = $order->get_id();
                
                // Verificar se já foi processado
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE order_id = %d",
                    $order_id
                ));
                
                if ($exists > 0) {
                    continue; // Já processado
                }
                
                // Obter dados do Instagram do pedido
                $instagram_username = $this->get_instagram_from_order($order);
                
                if (empty($instagram_username)) {
                    continue; // Sem Instagram
                }
                
                // Obter produtos do pedido
                $products = $this->get_products_from_order($order);
                
                if (empty($products)) {
                    continue; // Sem produtos válidos
                }
                
                // Salvar cada produto como uma prova social
                foreach ($products as $product) {
                    $data = [
                        'order_id' => $order_id,
                        'instagram_username' => sanitize_text_field($instagram_username),
                        'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'product_name' => $product['name'],
                        'product_price' => $product['price'],
                        'product_permalink' => $product['permalink'],
                        'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                        'status' => 'pending'
                    ];
                    
                    $result = $wpdb->insert($this->table_name, $data);
                    
                    if ($result !== false) {
                        $processed_count++;
                        $this->log_message("Prova social salva: pedido #{$order_id}, produto: {$product['name']}, Instagram: @{$instagram_username}");
                    }
                }
                
            } catch (Exception $e) {
                $this->log_message("Erro ao processar pedido #{$order_id}: " . $e->getMessage());
            }
        }
        
        return $processed_count;
    }
    
    /**
     * Buscar avatares pendentes via RapidAPI
     */
    private function fetch_pending_avatars($limit = 5) {
        global $wpdb;
        
        // Buscar usernames únicos que ainda não têm avatar válido
        $pending_usernames = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT sp.instagram_username 
            FROM {$this->table_name} sp 
            LEFT JOIN {$this->avatars_table_name} av ON sp.instagram_username = av.username 
            WHERE sp.status = 'pending' 
            AND sp.avatar_fetched = 0
            AND (av.username IS NULL OR av.status = 'expired' OR av.expires_at < NOW())
            LIMIT %d
        ", $limit));
        
        $fetched_count = 0;
        
        foreach ($pending_usernames as $username) {
            if ($this->fetch_instagram_avatar_rapidapi($username)) {
                $fetched_count++;
                
                // Marcar todas as provas sociais deste usuário como prontas
                $avatar_url = $this->get_cached_avatar($username);
                if ($avatar_url) {
                    $wpdb->update(
                        $this->table_name,
                        [
                            'avatar_url' => $avatar_url,
                            'avatar_fetched' => 1,
                            'status' => 'ready'
                        ],
                        ['instagram_username' => $username],
                        ['%s', '%d', '%s'],
                        ['%s']
                    );
                    
                    $this->log_message("Avatar fetchado e provas sociais atualizadas para @{$username}");
                }
            }
            
            // Delay entre requisições para evitar rate limiting
            sleep(1);
        }
        
        return $fetched_count;
    }
    
    /**
     * Buscar avatar do Instagram via RapidAPI
     */
    private function fetch_instagram_avatar_rapidapi($username) {
        global $wpdb;
        
        try {
            $api_key = get_option('instagram_rapidapi_key');
            $api_host = get_option('instagram_rapidapi_host', 'instagram-bulk-profile-scrapper.p.rapidapi.com');
            
            if (empty($api_key)) {
                $this->log_message("API key não configurada para buscar avatar de @{$username}");
                return false;
            }
            
            $this->log_message("Buscando avatar via RapidAPI para @{$username}");
            
            // Fazer requisição para RapidAPI
            $response = wp_remote_post("https://{$api_host}/clients/api/ig/ig_profile", [
                'headers' => [
                    'X-RapidAPI-Key' => $api_key,
                    'X-RapidAPI-Host' => $api_host,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'ig' => $username
                ]),
                'timeout' => 30
            ]);
            
            if (is_wp_error($response)) {
                throw new Exception('Erro na requisição: ' . $response->get_error_message());
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!$data || !isset($data[0]['profile_pic_url'])) {
                throw new Exception('Resposta inválida da API');
            }
            
            $avatar_url = $data[0]['profile_pic_url'];
            $avatar_hd_url = $data[0]['profile_pic_url_hd'] ?? $avatar_url;
            
            // Salvar no cache
            $cache_data = [
                'username' => $username,
                'avatar_url' => $avatar_url,
                'avatar_hd_url' => $avatar_hd_url,
                'expires_at' => date('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60)), // 7 dias
                'status' => 'valid'
            ];
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->avatars_table_name} WHERE username = %s",
                $username
            ));
            
            if ($existing) {
                $wpdb->update(
                    $this->avatars_table_name,
                    $cache_data,
                    ['username' => $username],
                    ['%s', '%s', '%s', '%s', '%s'],
                    ['%s']
                );
            } else {
                $wpdb->insert($this->avatars_table_name, $cache_data);
            }
            
            $this->log_message("Avatar salvo com sucesso para @{$username}: {$avatar_url}");
            return true;
            
        } catch (Exception $e) {
            $this->log_message("Erro ao buscar avatar para @{$username}: " . $e->getMessage());
            
            // Salvar erro no cache para evitar tentativas repetidas
            $error_data = [
                'username' => $username,
                'avatar_url' => $this->get_fallback_avatar($username),
                'expires_at' => date('Y-m-d H:i:s', time() + (24 * 60 * 60)), // 1 dia
                'last_error' => $e->getMessage(),
                'status' => 'error',
                'fetch_attempts' => 1
            ];
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->avatars_table_name} WHERE username = %s",
                $username
            ));
            
            if ($existing) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->avatars_table_name} SET fetch_attempts = fetch_attempts + 1, last_error = %s WHERE username = %s",
                    $e->getMessage(),
                    $username
                ));
            } else {
                $wpdb->insert($this->avatars_table_name, $error_data);
            }
            
            return false;
        }
    }
    
    /**
     * Obter avatar do cache local
     */
    private function get_cached_avatar($username) {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT avatar_url FROM {$this->avatars_table_name} WHERE username = %s AND status = 'valid' AND expires_at > NOW()",
            $username
        ));
        
        return $result ?: $this->get_fallback_avatar($username);
    }
    
    /**
     * Gerar avatar fallback
     */
    private function get_fallback_avatar($username) {
        $initial = strtoupper(substr($username, 0, 1));
        return "https://via.placeholder.com/100x100/E1306C/ffffff?text={$initial}";
    }
    
    /**
     * AJAX para marcar toast como exibido
     */
    public function mark_toast_displayed_ajax() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'instagram_toasts_nonce')) {
                wp_send_json_error('Nonce inválido');
                return;
            }
            
            $proof_id = intval($_POST['proof_id']);
            
            if ($proof_id <= 0) {
                wp_send_json_error('ID inválido');
                return;
            }
            
            global $wpdb;
            
            $result = $wpdb->update(
                $this->table_name,
                [
                    'display_count' => new \Exception('display_count + 1'), // Raw SQL
                    'last_displayed' => current_time('mysql'),
                    'status' => 'displayed'
                ],
                ['id' => $proof_id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            
            // Como não podemos usar raw SQL no $wpdb->update, vamos fazer com query direta
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table_name} SET display_count = display_count + 1, last_displayed = %s, status = 'displayed' WHERE id = %d",
                current_time('mysql'),
                $proof_id
            ));
            
            wp_send_json_success('Toast marcado como exibido');
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX handler para processar pedidos manualmente (admin)
     */
    public function process_background_orders() {
        try {
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Sem permissão');
                return;
            }
            
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'instagram_toasts_nonce')) {
                wp_send_json_error('Nonce inválido');
                return;
            }
            
            $processed_orders = $this->process_new_orders();
            $avatars_processed = $this->fetch_pending_avatars();
            
            wp_send_json_success([
                'orders_processed' => $processed_orders,
                'avatars_processed' => $avatars_processed,
                'message' => "Processamento concluído. Pedidos: {$processed_orders}, Avatares: {$avatars_processed}"
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Método para obter compras recentes com tratamento robusto de erros
     */
    private function get_recent_purchases() {
        try {
            // Verificar se WooCommerce está disponível
            if (!function_exists('wc_get_orders')) {
                throw new Exception('Função wc_get_orders não está disponível');
            }
            
            // Verificar cache
            $cache_key = 'instagram_recent_purchases';
            $cached = get_transient($cache_key);
            
            if ($cached !== false) {
                $this->log_message('Retornando dados do cache: ' . count($cached) . ' pedidos');
                return $cached;
            }
            
            $this->log_message('Cache não encontrado, buscando pedidos no banco');
            
            // Argumentos para buscar pedidos
            $args = [
                'status' => ['processing', 'completed'], // Incluir completed também
                'limit' => 20, // Aumentar limite para ter mais opções
                'orderby' => 'date',
                'order' => 'DESC',
                'return' => 'objects'
            ];
            
            $this->log_message('Argumentos da consulta: ' . print_r($args, true));
            
            // Buscar pedidos
            $orders = wc_get_orders($args);
            
            if (is_wp_error($orders)) {
                throw new Exception('Erro ao buscar pedidos: ' . $orders->get_error_message());
            }
            
            $this->log_message('Pedidos encontrados: ' . count($orders));
            
            $result = [];
            $processed_count = 0;
            
            foreach ($orders as $order) {
                try {
                    $processed_count++;
                    $order_id = $order->get_id();
                    
                    $this->log_message("Processando pedido #{$order_id} ({$processed_count}/" . count($orders) . ")");
                    
                    // Obter Instagram username dos itens do pedido
                    $instagram_username = $this->get_instagram_from_order($order);
                    
                    if (empty($instagram_username)) {
                        $this->log_message("Pedido #{$order_id} não tem username do Instagram");
                        continue;
                    }
                    
                    $this->log_message("Pedido #{$order_id} com Instagram: {$instagram_username}");
                    
                    // Obter produtos do pedido
                    $products = $this->get_products_from_order($order);
                    
                    if (empty($products)) {
                        $this->log_message("Pedido #{$order_id} não tem produtos válidos");
                        continue;
                    }
                    
                    // CORREÇÃO: Obter avatar sem tentar acessar método privado
                    $avatar_url = $this->get_instagram_avatar_safe($instagram_username);
                    
                    $purchase_data = [
                        'order_id' => $order_id,
                        'instagram_username' => $instagram_username,
                        'avatar_url' => $avatar_url,
                        'products' => $products,
                        'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                        'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'status' => $order->get_status()
                    ];
                    
                    $result[] = $purchase_data;
                    
                    $this->log_message("Pedido #{$order_id} adicionado à lista (total: " . count($result) . ")");
                    
                    // Limitar a 10 pedidos para não sobrecarregar
                    if (count($result) >= 10) {
                        $this->log_message('Limite de 10 pedidos atingido, parando');
                        break;
                    }
                    
                } catch (Exception $e) {
                    $this->log_message("Erro ao processar pedido #{$order_id}: " . $e->getMessage());
                    continue;
                }
            }
            
            $this->log_message('Total de pedidos processados com Instagram: ' . count($result));
            
            // Armazenar em cache por 1 hora (reduzido para testes)
            set_transient($cache_key, $result, 3600);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log_message('ERRO na função get_recent_purchases: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obter Instagram username de um pedido
     */
    private function get_instagram_from_order($order) {
        try {
            $items = $order->get_items();
            
            foreach ($items as $item) {
                // Tentar diferentes variações do meta field
                $instagram_fields = ['Instagram', 'instagram', '_instagram', 'instagram_username'];
                
                foreach ($instagram_fields as $field) {
                    $instagram = $item->get_meta($field);
                    if (!empty($instagram)) {
                        $this->log_message("Instagram encontrado no campo '{$field}': {$instagram}");
                        return trim($instagram);
                    }
                }
                
                // Verificar se está nos meta dados customizados do pedido
                $order_meta_fields = ['Instagram', 'instagram', '_instagram', 'instagram_username'];
                foreach ($order_meta_fields as $field) {
                    $instagram = $order->get_meta($field);
                    if (!empty($instagram)) {
                        $this->log_message("Instagram encontrado no meta do pedido '{$field}': {$instagram}");
                        return trim($instagram);
                    }
                }
            }
            
            // Log dos meta fields disponíveis para debug
            $this->log_message("Meta fields do pedido #{$order->get_id()}: " . print_r($order->get_meta_data(), true));
            
            return '';
            
        } catch (Exception $e) {
            $this->log_message('Erro ao obter Instagram do pedido: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Obter produtos de um pedido
     */
    private function get_products_from_order($order) {
        try {
            $products = [];
            $items = $order->get_items();
            
            foreach ($items as $item) {
                $product = $item->get_product();
                if ($product) {
                    $image_id = $product->get_image_id();
                    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
                    
                    $products[] = [
                        'name' => $product->get_name(),
                        'permalink' => $product->get_permalink(),
                        'image_url' => $image_url,
                        'price' => $product->get_price()
                    ];
                }
            }
            
            return $products;
            
        } catch (Exception $e) {
            $this->log_message('Erro ao obter produtos do pedido: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * MÉTODO CORRIGIDO: Obter avatar do Instagram de forma segura
     * Este método evita o erro de método privado
     */
    private function get_instagram_avatar_safe($username) {
        try {
            $cache_key = 'instagram_avatar_' . md5($username);
            $cached_avatar = get_transient($cache_key);
            
            // Retornar do cache se existir
            if ($cached_avatar !== false) {
                $this->log_message("Avatar encontrado no cache para @{$username}");
                return $cached_avatar;
            }
            
            $this->log_message("Cache não encontrado para @{$username}, tentando buscar");
            
            // CORREÇÃO: Tentar buscar avatar com verificações de segurança
            $avatar_url = $this->fetch_instagram_avatar_safe($username);
            
            if ($avatar_url) {
                $this->log_message("Avatar obtido com sucesso para @{$username}: {$avatar_url}");
                // Armazenar por 7 dias
                set_transient($cache_key, $avatar_url, 7 * DAY_IN_SECONDS);
                return $avatar_url;
            }
            
            // Fallback para avatar padrão
            $fallback = 'https://via.placeholder.com/50x50/E1306C/ffffff?text=' . strtoupper(substr($username, 0, 1));
            set_transient($cache_key, $fallback, 7 * DAY_IN_SECONDS);
            
            $this->log_message("Usando avatar fallback para @{$username}: {$fallback}");
            return $fallback;
            
        } catch (Exception $e) {
            $this->log_message('Erro ao obter avatar do Instagram: ' . $e->getMessage());
            return 'https://via.placeholder.com/50x50/cccccc/999999?text=?';
        }
    }
    
    /**
     * MÉTODO CORRIGIDO: Buscar avatar do Instagram de forma segura
     * Evita chamar métodos privados de outras classes
     */
    private function fetch_instagram_avatar_safe($username) {
        try {
            $this->log_message("Tentando buscar avatar para @{$username}");
            
            // ABORDAGEM 1: Verificar se existe método público na classe ConsultaInstagramPlugin
            if (class_exists('ConsultaInstagramPlugin')) {
                $this->log_message("Plugin ConsultaInstagramPlugin encontrado");
                
                // Verificar se existe uma instância global ou método estático público
                global $consulta_instagram_plugin;
                
                if (isset($consulta_instagram_plugin) && is_object($consulta_instagram_plugin)) {
                    // Verificar se tem método público
                    if (method_exists($consulta_instagram_plugin, 'get_user_avatar') || 
                        method_exists($consulta_instagram_plugin, 'consultar_usuario_publico')) {
                        
                        $this->log_message("Método público encontrado na instância global");
                        
                        // Tentar usar método público se existir
                        if (method_exists($consulta_instagram_plugin, 'get_user_avatar')) {
                            $result = $consulta_instagram_plugin->get_user_avatar($username);
                        } else {
                            $result = $consulta_instagram_plugin->consultar_usuario_publico($username);
                        }
                        
                        if (!empty($result) && is_array($result) && isset($result['profile_pic_url'])) {
                            return $result['profile_pic_url'];
                        }
                    }
                }
                
                // ABORDAGEM 2: Tentar criar nova instância e usar método público
                try {
                    $instagram_plugin = new ConsultaInstagramPlugin();
                    
                    // Verificar métodos públicos disponíveis
                    $reflection = new ReflectionClass($instagram_plugin);
                    $public_methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
                    
                    $this->log_message("Métodos públicos disponíveis: " . implode(', ', array_column($public_methods, 'name')));
                    
                    // Procurar por métodos que possam retornar dados do Instagram
                    foreach ($public_methods as $method) {
                        $method_name = $method->getName();
                        
                        // Tentar métodos que parecem ser para consulta pública
                        if (strpos($method_name, 'consultar') !== false && 
                            strpos($method_name, 'publico') !== false) {
                            
                            $this->log_message("Tentando usar método público: {$method_name}");
                            $result = $instagram_plugin->$method_name($username);
                            
                            if (!empty($result) && is_array($result) && isset($result['profile_pic_url'])) {
                                $this->log_message("Sucesso com método {$method_name}");
                                return $result['profile_pic_url'];
                            }
                        }
                    }
                    
                } catch (ReflectionException $e) {
                    $this->log_message("Erro de reflexão: " . $e->getMessage());
                } catch (Exception $e) {
                    $this->log_message("Erro ao tentar nova instância: " . $e->getMessage());
                }
            }
            
            // ABORDAGEM 3: Usar hook/filtro do WordPress se existir
            $avatar_from_hook = apply_filters('instagram_get_user_avatar', false, $username);
            if (!empty($avatar_from_hook) && is_string($avatar_from_hook)) {
                $this->log_message("Avatar obtido via hook WordPress para @{$username}");
                return $avatar_from_hook;
            }
            
            // ABORDAGEM 4: Verificar se existe função global
            if (function_exists('consultar_instagram_usuario')) {
                $this->log_message("Função global consultar_instagram_usuario encontrada");
                $result = consultar_instagram_usuario($username);
                
                if (!empty($result) && is_array($result) && isset($result['profile_pic_url'])) {
                    $this->log_message("Sucesso com função global");
                    return $result['profile_pic_url'];
                }
            }
            
            // ABORDAGEM 5: API própria simplificada (se você tiver acesso à API)
            $avatar_url = $this->fetch_instagram_avatar_direct($username);
            if (!empty($avatar_url)) {
                return $avatar_url;
            }
            
            $this->log_message("Nenhuma abordagem funcionou para @{$username}, usando fallback");
            return false;
            
        } catch (Exception $e) {
            $this->log_message('Erro ao buscar avatar do Instagram: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * NOVO MÉTODO: Buscar avatar diretamente (implementar sua própria lógica)
     * Substitua este método pela sua própria implementação de API
     */
    private function fetch_instagram_avatar_direct($username) {
        try {
            // OPÇÃO 1: Se você tem suas próprias credenciais de API do Instagram
            /*
            $api_key = get_option('instagram_api_key');
            if (!empty($api_key)) {
                // Implementar sua própria chamada de API aqui
                // Exemplo com RapidAPI (substitua pela sua implementação)
                $response = wp_remote_get("https://instagram-basic-info.p.rapidapi.com/user/{$username}url_embed_safe=true", [
                    'headers' => [
                        'X-RapidAPI-Key' => $api_key,
                        'X-RapidAPI-Host' => 'instagram-basic-info.p.rapidapi.com'
                    ],
                    'timeout' => 10
                ]);
                
                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    
                    if (isset($data['profile_pic_url'])) {
                        return $data['profile_pic_url'];
                    }
                }
            }
            */
            
            // OPÇÃO 2: Usar serviço público (menos confiável)
            /*
            $response = wp_remote_get("https://www.instagram.com/{$username}/?__a=1", [
                'timeout' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                if (isset($data['graphql']['user']['profile_pic_url'])) {
                    return $data['graphql']['user']['profile_pic_url'];
                }
            }
            */
            
            $this->log_message("Método direto não implementado para @{$username}");
            return false;
            
        } catch (Exception $e) {
            $this->log_message('Erro no método direto: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * MÉTODO CORRIGIDO: Versão segura que não causa erro fatal
     * Remove a chamada para método privado
     */
    private function get_instagram_avatar($username) {
        return $this->get_instagram_avatar_safe($username);
    }
    
    /**
     * NOVO MÉTODO: Hook para permitir que outros plugins forneçam avatares
     * Outros plugins podem usar: add_filter('instagram_purchase_toasts_avatar', 'minha_funcao', 10, 2);
     */
    private function get_avatar_from_hooks($username) {
        $avatar_url = apply_filters('instagram_purchase_toasts_avatar', false, $username);
        
        if (!empty($avatar_url) && is_string($avatar_url)) {
            $this->log_message("Avatar obtido via hook para @{$username}: {$avatar_url}");
            return $avatar_url;
        }
        
        return false;
    }
    
    // Método para debug dos pedidos
    private function debug_order_data() {
        try {
            $args = [
                'status' => ['processing', 'completed'],
                'limit' => 20,
                'orderby' => 'date',
                'order' => 'DESC'
            ];
            
            $orders = wc_get_orders($args);
            $debug_info = [];
            
            foreach ($orders as $order) {
                $instagram_username = $this->get_instagram_from_order($order);
                
                $debug_info[] = [
                    'order_id' => $order->get_id(),
                    'status' => $order->get_status(),
                    'instagram' => $instagram_username,
                    'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                    'items' => array_map(function($item) {
                        return $item->get_name();
                    }, $order->get_items()),
                    'meta_data' => array_slice($order->get_meta_data(), 0, 5) // Primeiros 5 meta fields
                ];
            }
            
            return $debug_info;
            
        } catch (Exception $e) {
            $this->log_message('Erro no debug_order_data: ' . $e->getMessage());
            return [];
        }
    }

    // Método para verificar pedidos manualmente
    public function debug_orders_ajax() {
        try {
            if (!wp_verify_nonce($_POST['nonce'], 'instagram_toasts_nonce')) {
                wp_send_json_error('Invalid nonce');
                return;
            }
            
            $orders = $this->debug_order_data();
            wp_send_json_success($orders);
            
        } catch (Exception $e) {
            $this->log_message('Erro no debug_orders_ajax: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    public function force_cache_update_ajax() {
        try {
            if (!wp_verify_nonce($_POST['nonce'], 'instagram_toasts_nonce')) {
                wp_send_json_error('Invalid nonce');
                return;
            }
            
            // Limpar cache
            delete_transient('instagram_recent_purchases');
            
            // Também limpar cache de avatares
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_instagram_avatar_%',
                    '_transient_timeout_instagram_avatar_%'
                )
            );
            
            $this->log_message('Cache forçado a atualizar via AJAX');
            wp_send_json_success('Cache atualizado');
            
        } catch (Exception $e) {
            $this->log_message('Erro no force_cache_update_ajax: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('instagram-purchase-toasts', plugin_dir_url(__FILE__) . 'style.css');
        wp_enqueue_script('instagram-purchase-toasts', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], '1.2', true);
        
        wp_localize_script('instagram-purchase-toasts', 'instagram_toasts', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('instagram_toasts_nonce'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ]);
    }
    
    public function display_toasts_container() {
        echo '<div id="instagram-purchase-toasts"></div>';
    }
    
    public function cleanup_old_cache() {
        try {
            global $wpdb;
            
            // Limpar transients antigos (mais de 30 dias)
            $time = time();
            $wpdb->query(
                $wpdb->prepare("
                    DELETE FROM $wpdb->options 
                    WHERE option_name LIKE %s 
                    AND option_value < %d
                ", 
                '_transient_instagram_avatar_%', 
                $time - (30 * DAY_IN_SECONDS))
            );
            
            $this->log_message('Limpeza automática de cache executada');
            
        } catch (Exception $e) {
            $this->log_message('Erro na limpeza de cache: ' . $e->getMessage());
        }
    }
    
    /**
     * Adicionar menu de administração
     */
    public function add_admin_menu() {
        add_menu_page(
            'Instagram Purchase Toasts',
            'Instagram Toasts',
            'manage_options',
            'instagram-purchase-toasts',
            [$this, 'display_admin_page'],
            'dashicons-megaphone',
            100
        );
        
        add_submenu_page(
            'instagram-purchase-toasts',
            'Dashboard Principal',
            '📊 Dashboard',
            'manage_options',
            'instagram-purchase-toasts',
            [$this, 'display_admin_page']
        );
        
        add_submenu_page(
            'instagram-purchase-toasts',
            'Provas Sociais',
            '🎯 Provas Sociais',
            'manage_options',
            'instagram-toasts-social-proofs',
            [$this, 'display_social_proofs_page']
        );
        
        add_submenu_page(
            'instagram-purchase-toasts',
            'Configurações RapidAPI',
            '🔧 RapidAPI',
            'manage_options',
            'instagram-toasts-api',
            [$this, 'display_api_page']
        );
        
        add_submenu_page(
            'instagram-purchase-toasts',
            'Debug & Diagnóstico',
            '🔍 Debug',
            'manage_options',
            'instagram-toasts-debug',
            [$this, 'display_debug_page']
        );
    }
    
    /**
     * NOVA PÁGINA: Configurações de API
     */
    public function display_api_page() {
        // Salvar configurações se enviadas
        if (isset($_POST['save_api_settings']) && check_admin_referer('api_settings')) {
            update_option('instagram_rapidapi_key', sanitize_text_field($_POST['rapidapi_key']));
            update_option('instagram_rapidapi_host', sanitize_text_field($_POST['rapidapi_host']));
            
            echo '<div class="notice notice-success"><p>Configurações RapidAPI salvas com sucesso!</p></div>';
        }
        
        // Processar pedidos manualmente se solicitado
        if (isset($_POST['process_orders_manual']) && check_admin_referer('process_orders')) {
            $processed_orders = $this->process_new_orders();
            $avatars_processed = $this->fetch_pending_avatars(10);
            echo '<div class="notice notice-success"><p>Processamento manual concluído! Pedidos: ' . $processed_orders . ', Avatares: ' . $avatars_processed . '</p></div>';
        }
        
        $rapidapi_key = get_option('instagram_rapidapi_key', '');
        $rapidapi_host = get_option('instagram_rapidapi_host', 'instagram-bulk-profile-scrapper.p.rapidapi.com');
        
        // Estatísticas do banco
        global $wpdb;
        $total_proofs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $ready_proofs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'ready'");
        $pending_proofs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'");
        $total_avatars = $wpdb->get_var("SELECT COUNT(*) FROM {$this->avatars_table_name}");
        $valid_avatars = $wpdb->get_var("SELECT COUNT(*) FROM {$this->avatars_table_name} WHERE status = 'valid' AND expires_at > NOW()");
        ?>
        <div class="wrap">
            <h1>🚀 Configurações RapidAPI - Instagram Toasts</h1>
            
            <div class="card">
                <h2>📊 Estatísticas do Sistema</h2>
                <table class="widefat">
                    <tr>
                        <td><strong>Total de Provas Sociais:</strong></td>
                        <td><?php echo $total_proofs; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Prontas para Exibição:</strong></td>
                        <td style="color: green;"><strong><?php echo $ready_proofs; ?></strong></td>
                    </tr>
                    <tr>
                        <td><strong>Aguardando Avatar:</strong></td>
                        <td style="color: orange;"><?php echo $pending_proofs; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Avatares no Cache:</strong></td>
                        <td><?php echo $total_avatars; ?> (<?php echo $valid_avatars; ?> válidos)</td>
                    </tr>
                </table>
            </div>
            
            <div class="card">
                <h2>⚙️ Configuração RapidAPI</h2>
                <p>Configure sua chave RapidAPI para buscar avatares do Instagram automaticamente.</p>
                
                <form method="post">
                    <?php wp_nonce_field('api_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="rapidapi_key">RapidAPI Key</label>
                            </th>
                            <td>
                                <input type="password" id="rapidapi_key" name="rapidapi_key" value="<?php echo esc_attr($rapidapi_key); ?>" class="regular-text" />
                                <p class="description">Sua chave da RapidAPI. <a href="https://rapidapi.com/hub" target="_blank">Obter chave aqui</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="rapidapi_host">RapidAPI Host</label>
                            </th>
                            <td>
                                <input type="text" id="rapidapi_host" name="rapidapi_host" value="<?php echo esc_attr($rapidapi_host); ?>" class="regular-text" />
                                <p class="description">Host da API Instagram (padrão: instagram-bulk-profile-scrapper.p.rapidapi.com)</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="save_api_settings" class="button-primary" value="💾 Salvar Configurações" />
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2>🔄 Processamento Manual</h2>
                <p>Force o processamento de novos pedidos e busca de avatares.</p>
                
                <form method="post">
                    <?php wp_nonce_field('process_orders'); ?>
                    <p class="submit">
                        <input type="submit" name="process_orders_manual" class="button-secondary" value="🚀 Processar Pedidos Agora" />
                    </p>
                </form>
            </div>
                
                <h3>Instruções</h3>
                <div class="notice notice-info">
                    <p><strong>Como resolver o problema de método privado:</strong></p>
                    <ol>
                        <li><strong>Opção 1:</strong> Configure suas próprias credenciais de API acima</li>
                        <li><strong>Opção 2:</strong> Peça ao desenvolvedor do plugin ConsultaInstagramPlugin para tornar o método público</li>
                        <li><strong>Opção 3:</strong> Use apenas avatares de fallback (placeholder)</li>
                        <li><strong>Opção 4:</strong> Implemente hook personalizado no tema ou outro plugin</li>
                    </ol>
                    <p>O plugin funcionará com avatares de fallback mesmo sem API configurada.</p>
                </div>
                
                <h3>Teste de Conectividade</h3>
                <button type="button" id="test-api-connection" class="button">Testar Conexão com API</button>
                <div id="api-test-result" style="margin-top: 10px;"></div>
                
                <script>
                document.getElementById('test-api-connection').addEventListener('click', function() {
                    const apiKey = document.getElementById('api_key').value;
                    const endpoint = document.getElementById('api_endpoint').value;
                    const resultDiv = document.getElementById('api-test-result');
                    
                    if (!apiKey || !endpoint) {
                        resultDiv.innerHTML = '<div class="notice notice-warning"><p>Configure a chave da API e endpoint primeiro.</p></div>';
                        return;
                    }
                    
                    resultDiv.innerHTML = '<p>Testando conexão...</p>';
                    
                    // Fazer teste simples
                    fetch(endpoint.replace('{username}', 'instagram'), {
                        headers: {
                            'X-RapidAPI-Key': apiKey
                        }
                    })
                    .then(response => {
                        if (response.ok) {
                            resultDiv.innerHTML = '<div class="notice notice-success"><p>✓ Conexão funcionando!</p></div>';
                        } else {
                            resultDiv.innerHTML = '<div class="notice notice-error"><p>✗ Erro: ' + response.status + '</p></div>';
                        }
                    })
                    .catch(error => {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>✗ Erro de conexão: ' + error.message + '</p></div>';
                    });
                });
                </script>
            </div>
        </div>
        <?php
    }
    
    /**
     * Manipular ações do admin
     */
    public function handle_admin_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'instagram-toasts-debug') {
            return;
        }
        
        // Ação: Ativar modo de manutenção
        if (isset($_GET['action']) && $_GET['action'] === 'maintenance_on' && check_admin_referer('maintenance_on')) {
            update_option('instagram_toasts_maintenance', true);
            wp_redirect(admin_url('admin.php?page=instagram-toasts-debug&maintenance_on=1'));
            exit;
        }
        
        // Ação: Desativar modo de manutenção
        if (isset($_GET['action']) && $_GET['action'] === 'maintenance_off' && check_admin_referer('maintenance_off')) {
            delete_option('instagram_toasts_maintenance');
            wp_redirect(admin_url('admin.php?page=instagram-toasts-debug&maintenance_off=1'));
            exit;
        }
        
        // Ação: Limpar cache
        if (isset($_GET['action']) && $_GET['action'] === 'clear_cache' && check_admin_referer('clear_cache')) {
            $this->clear_all_cache();
            wp_redirect(admin_url('admin.php?page=instagram-toasts-debug&cache_cleared=1'));
            exit;
        }
        
        // Ação: Forçar atualização
        if (isset($_GET['action']) && $_GET['action'] === 'force_update' && check_admin_referer('force_update')) {
            delete_transient('instagram_recent_purchases');
            wp_redirect(admin_url('admin.php?page=instagram-toasts-debug&force_updated=1'));
            exit;
        }
        
        // Ação: Limpar log
        if (isset($_GET['action']) && $_GET['action'] === 'clear_log' && check_admin_referer('clear_log')) {
            $log_file = plugin_dir_path(__FILE__) . 'debug.log';
            if (file_exists($log_file)) {
                unlink($log_file);
            }
            wp_redirect(admin_url('admin.php?page=instagram-toasts-debug&log_cleared=1'));
            exit;
        }
        
        // Ação: Testar toast
        if (isset($_POST['test_toast']) && check_admin_referer('test_toast')) {
            $this->test_toast_display();
        }
        
        // NOVA AÇÃO: Testar sem avatares do Instagram
        if (isset($_GET['action']) && $_GET['action'] === 'test_fallback_mode' && check_admin_referer('test_fallback_mode')) {
            $this->enable_fallback_mode();
            wp_redirect(admin_url('admin.php?page=instagram-toasts-debug&fallback_mode=1'));
            exit;
        }
    }
    
    /**
     * NOVO MÉTODO: Ativar modo fallback
     */
    private function enable_fallback_mode() {
        update_option('instagram_toasts_fallback_mode', true);
        $this->log_message('Modo fallback ativado - apenas avatares placeholder serão usados');
    }
    
    /**
     * Página principal de administração
     */
    public function display_admin_page() {
        $maintenance_mode = get_option('instagram_toasts_maintenance', false);
        $rapidapi_key = get_option('instagram_rapidapi_key', '');
        
        // Estatísticas do banco
        global $wpdb;
        $stats = [
            'total_proofs' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'ready_proofs' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'ready'"),
            'pending_proofs' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'"),
            'displayed_proofs' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'displayed'"),
            'total_avatars' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->avatars_table_name}"),
            'valid_avatars' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->avatars_table_name} WHERE status = 'valid' AND expires_at > NOW()"),
            'recent_orders' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'last_processing' => $wpdb->get_var("SELECT MAX(created_at) FROM {$this->table_name}")
        ];
        
        $next_cron = wp_next_scheduled('instagram_process_orders');
        ?>
        <div class="wrap">
            <h1>🚀 Instagram Purchase Toasts - Dashboard</h1>
            
            <?php if ($maintenance_mode): ?>
                <div class="notice notice-warning">
                    <p><strong>⚠️ Modo de Manutenção Ativo</strong> - O plugin está pausado temporariamente.</p>
                </div>
            <?php endif; ?>
            
            <?php if (empty($rapidapi_key)): ?>
                <div class="notice notice-warning">
                    <p><strong>⚠️ RapidAPI não configurada</strong> - Configure sua chave API para buscar avatares do Instagram. 
                    <a href="<?php echo admin_url('admin.php?page=instagram-toasts-api'); ?>">Configurar agora</a></p>
                </div>
            <?php endif; ?>
            
            <!-- Estatísticas Gerais -->
            <div class="card">
                <h2>📊 Estatísticas em Tempo Real</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    <div style="background: #e8f5e8; padding: 15px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0; color: #2d5a2d;">✅ Prontas</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #1e7e34;"><?php echo $stats['ready_proofs']; ?></div>
                        <small>Provas sociais prontas para exibição</small>
                    </div>
                    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0; color: #856404;">⏳ Pendentes</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #b8860b;"><?php echo $stats['pending_proofs']; ?></div>
                        <small>Aguardando busca de avatar</small>
                    </div>
                    <div style="background: #d1ecf1; padding: 15px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0; color: #0c5460;">📺 Exibidas</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #17a2b8;"><?php echo $stats['displayed_proofs']; ?></div>
                        <small>Já foram mostradas aos visitantes</small>
                    </div>
                    <div style="background: #f8d7da; padding: 15px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0; color: #721c24;">📱 Avatares</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #dc3545;"><?php echo $stats['valid_avatars']; ?>/<?php echo $stats['total_avatars']; ?></div>
                        <small>Avatares válidos no cache</small>
                    </div>
                </div>
            </div>
            
            <!-- Status do Sistema -->
            <div class="card">
                <h2>🔧 Status do Sistema</h2>
                <table class="widefat">
                    <tr>
                        <td><strong>WooCommerce:</strong></td>
                        <td><?php echo class_exists('WooCommerce') ? '<span style="color: green;">✅ Ativo</span>' : '<span style="color: red;">❌ Inativo</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>RapidAPI:</strong></td>
                        <td><?php echo !empty($rapidapi_key) ? '<span style="color: green;">✅ Configurada</span>' : '<span style="color: orange;">⚠️ Não configurada</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Modo de Manutenção:</strong></td>
                        <td><?php echo $maintenance_mode ? '<span style="color: orange;">⚠️ Ativo</span>' : '<span style="color: green;">✅ Desativo</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Próximo Processamento:</strong></td>
                        <td><?php echo $next_cron ? '<span style="color: blue;">📅 ' . date('d/m/Y H:i', $next_cron) . '</span>' : '<span style="color: red;">❌ Não agendado</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Último Processamento:</strong></td>
                        <td><?php echo $stats['last_processing'] ? '<span style="color: green;">⏰ ' . date('d/m/Y H:i', strtotime($stats['last_processing'])) . '</span>' : '<span style="color: gray;">Nunca</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Novos pedidos (24h):</strong></td>
                        <td><span style="color: blue;">📈 <?php echo $stats['recent_orders']; ?> pedidos</span></td>
                    </tr>
                </table>
            </div>
            
            <!-- Ações Rápidas -->
            <div class="card">
                <h2>⚡ Ações Rápidas</h2>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="<?php echo admin_url('admin.php?page=instagram-toasts-social-proofs'); ?>" class="button button-primary">
                        🎯 Gerenciar Provas Sociais
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=instagram-toasts-api'); ?>" class="button button-secondary">
                        🔧 Configurar RapidAPI
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=instagram-toasts-debug'); ?>" class="button">
                        🔍 Debug & Diagnóstico
                    </a>
                    <button type="button" id="force-processing" class="button">
                        🚀 Processar Agora
                    </button>
                </div>
            </div>
            
            <script>
            document.getElementById('force-processing').addEventListener('click', function() {
                this.disabled = true;
                this.innerHTML = '⏳ Processando...';
                
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'process_background_orders',
                        nonce: '<?php echo wp_create_nonce("instagram_toasts_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ ' + response.data.message);
                            location.reload();
                        } else {
                            alert('❌ Erro: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('❌ Erro na comunicação com o servidor');
                    },
                    complete: function() {
                        document.getElementById('force-processing').disabled = false;
                        document.getElementById('force-processing').innerHTML = '🚀 Processar Agora';
                    }
                });
            });
            </script>
            </div>
        </div>
        <?php
    }
    
    /**
     * Página de debug e teste (atualizada)
     */
    public function display_debug_page() {
        $maintenance_mode = get_option('instagram_toasts_maintenance', false);
        $fallback_mode = get_option('instagram_toasts_fallback_mode', false);
        
        // Verificar se há mensagens de status
        if (isset($_GET['cache_cleared'])) {
            echo '<div class="notice notice-success"><p>Cache limpo com sucesso!</p></div>';
        }
        
        if (isset($_GET['force_updated'])) {
            echo '<div class="notice notice-success"><p>Cache forçado a atualizar!</p></div>';
        }
        
        if (isset($_GET['log_cleared'])) {
            echo '<div class="notice notice-success"><p>Log limpo com sucesso!</p></div>';
        }
        
        if (isset($_GET['maintenance_on'])) {
            echo '<div class="notice notice-success"><p>Modo de manutenção ativado!</p></div>';
        }
        
        if (isset($_GET['maintenance_off'])) {
            echo '<div class="notice notice-success"><p>Modo de manutenção desativado!</p></div>';
        }
        
        if (isset($_GET['fallback_mode'])) {
            echo '<div class="notice notice-success"><p>Modo fallback ativado!</p></div>';
        }
        
        // Obter dados atuais
        $cached_data = get_transient('instagram_recent_purchases');
        $avatar_count = $this->count_cached_avatars();
        ?>
        <div class="wrap">
            <h1>Instagram Purchase Toasts - Debug & Test</h1>
            
            <div class="card">
                <h2>Diagnóstico do Erro</h2>
                <div class="notice notice-error">
                    <p><strong>Erro Identificado:</strong> Tentativa de acesso a método privado</p>
                    <code>Call to private method ConsultaInstagramPlugin::consultar_instagram_rapidapi()</code>
                </div>
                
                <p><strong>Status da Correção:</strong></p>
                <ul>
                    <li>✓ Método corrigido para não chamar função privada</li>
                    <li>✓ Implementadas múltiplas abordagens de fallback</li>
                    <li>✓ Adicionado modo fallback para usar apenas placeholders</li>
                    <li>✓ Sistema de hooks para integração com outros plugins</li>
                </ul>
                
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=instagram-toasts-debug&action=test_fallback_mode'), 'test_fallback_mode'); ?>" class="button button-secondary">
                        Ativar Modo Fallback (Somente Placeholders)
                    </a>
                </p>
            </div>
            
            <div class="card">
                <h2>Controle de Sistema</h2>
                <?php if ($maintenance_mode): ?>
                    <div class="notice notice-warning inline">
                        <p>Plugin em modo de manutenção - todas as funcionalidades estão pausadas.</p>
                    </div>
                    <p>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=instagram-toasts-debug&action=maintenance_off'), 'maintenance_off'); ?>" class="button button-primary">
                            Desativar Modo de Manutenção
                        </a>
                    </p>
                <?php else: ?>
                    <p>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=instagram-toasts-debug&action=maintenance_on'), 'maintenance_on'); ?>" class="button button-secondary">
                            Ativar Modo de Manutenção
                        </a>
                    </p>
                <?php endif; ?>
                
                <h3>Ações</h3>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=instagram-toasts-debug&action=clear_cache'), 'clear_cache'); ?>" class="button">
                        Limpar Todo o Cache
                    </a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=instagram-toasts-debug&action=force_update'), 'force_update'); ?>" class="button">
                        Forçar Atualização
                    </a>
                </p>
            </div>
            
            <div class="card">
                <h2>Informações do Cache</h2>
                <p><strong>Avatares em cache:</strong> <?php echo $avatar_count; ?></p>
                <p><strong>Pedidos em cache:</strong> <?php echo $cached_data ? count($cached_data) : 0; ?></p>
                <p><strong>Modo Fallback:</strong> <?php echo $fallback_mode ? 'Ativo' : 'Inativo'; ?></p>
                
                <h3>Pedidos Recentes (Cache)</h3>
                <?php if ($cached_data && is_array($cached_data)) : ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>ID do Pedido</th>
                                <th>Status</th>
                                <th>Username</th>
                                <th>Produtos</th>
                                <th>Data</th>
                                <th>Avatar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cached_data as $order) : ?>
                                <tr>
                                    <td><?php echo esc_html($order['order_id']); ?></td>
                                    <td><?php echo esc_html($order['status'] ?? 'N/A'); ?></td>
                                    <td>@<?php echo esc_html($order['instagram_username']); ?></td>
                                    <td>
                                        <?php 
                                        foreach ($order['products'] as $product) {
                                            echo esc_html($product['name']) . '<br>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($order['order_date']); ?></td>
                                    <td>
                                        <?php if (!empty($order['avatar_url'])): ?>
                                            <img src="<?php echo esc_url($order['avatar_url']); ?>" width="30" height="30" style="border-radius: 50%;">
                                        <?php else: ?>
                                            <span style="color: #999;">Sem avatar</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>Nenhum pedido em cache.</p>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2>Log de Debug</h2>
                <textarea rows="15" style="width: 100%; font-family: monospace;" readonly><?php 
                    $log_file = plugin_dir_path(__FILE__) . 'debug.log';
                    if (file_exists($log_file)) {
                        $log_content = file_get_contents($log_file);
                        // Mostrar apenas as últimas 50 linhas
                        $lines = explode("\n", $log_content);
                        $recent_lines = array_slice($lines, -50);
                        echo esc_textarea(implode("\n", $recent_lines));
                    } else {
                        echo 'Nenhum log encontrado.';
                    }
                ?></textarea>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=instagram-toasts-debug&action=clear_log'), 'clear_log'); ?>" class="button">
                        Limpar Log
                    </a>
                    <span class="description">Mostrando as últimas 50 linhas do log</span>
                </p>
            </div>
            
            <div class="card">
                <h2>Teste Manual</h2>
                <form method="post">
                    <?php wp_nonce_field('test_toast'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="test_username">Username do Instagram</label></th>
                            <td><input type="text" id="test_username" name="test_username" value="testuser" /></td>
                        </tr>
                        <tr>
                            <th><label for="test_product">Nome do Produto</label></th>
                            <td><input type="text" id="test_product" name="test_product" value="Produto de Teste" /></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="test_toast" class="button button-primary" value="Criar Toast de Teste" />
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Contar avatares em cache
     */
    private function count_cached_avatars() {
        global $wpdb;
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s",
                '_transient_instagram_avatar_%'
            )
        );
        
        return $count ? $count : 0;
    }
    
    /**
     * Limpar todo o cache
     */
    private function clear_all_cache() {
        global $wpdb;
        
        // Limpar cache de pedidos
        delete_transient('instagram_recent_purchases');
        
        // Limpar cache de avatares
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_instagram_avatar_%',
                '_transient_timeout_instagram_avatar_%'
            )
        );
        
        // Log da ação
        $this->log_message('Cache limpo manualmente pelo administrador');
    }
    
    /**
     * Testar exibição de toast
     */
    private function test_toast_display() {
        $username = sanitize_text_field($_POST['test_username'] ?? 'testuser');
        $product = sanitize_text_field($_POST['test_product'] ?? 'Test Product');
        
        // Adicionar à fila de toasts
        $test_data = [
            [
                'order_id' => 'test-' . time(),
                'instagram_username' => $username,
                'avatar_url' => 'https://via.placeholder.com/50x50/E1306C/ffffff?text=' . strtoupper(substr($username, 0, 1)),
                'products' => [
                    ['name' => $product, 'permalink' => '#', 'price' => '99.90']
                ],
                'order_date' => date('Y-m-d H:i:s'),
                'customer_name' => 'Test Customer'
            ]
        ];
        
        // Armazenar temporariamente para o frontend
        set_transient('instagram_test_toast', $test_data, 60);
        
        // Mensagem de sucesso
        echo '<div class="notice notice-success"><p>Toast de teste configurado! Verifique o frontend do site.</p></div>';
        
        // Log
        $this->log_message('Toast de teste criado para @' . $username);
    }
    
    /**
     * AJAX para testar toast no frontend
     */
    public function test_toast_ajax() {
        try {
            if (!wp_verify_nonce($_POST['_ajax_nonce'], 'test_toast_nonce')) {
                wp_send_json_error('Nonce inválido');
                return;
            }
            
            $test_data = get_transient('instagram_test_toast');
            
            if ($test_data) {
                wp_send_json_success($test_data);
            } else {
                wp_send_json_error('Nenhum teste configurado');
            }
            
        } catch (Exception $e) {
            $this->log_message('Erro no test_toast_ajax: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Registrar mensagem de log com rotação
     */
    private function log_message($message) {
        try {
            $log_file = plugin_dir_path(__FILE__) . 'debug.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[$timestamp] $message\n";
            
            // Verificar tamanho do arquivo de log
            if (file_exists($log_file) && filesize($log_file) > 1048576) { // 1MB
                // Fazer backup das últimas 100 linhas
                $lines = file($log_file);
                $recent_lines = array_slice($lines, -100);
                file_put_contents($log_file, implode('', $recent_lines), LOCK_EX);
                $this->log_message('Log rotacionado - mantidas as últimas 100 entradas');
            }
            
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            
        } catch (Exception $e) {
            // Se não conseguir escrever no log, pelo menos tentar error_log
            error_log('Instagram Toasts Log Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Função para diagnóstico completo do sistema
     */
    public function system_diagnostic() {
        $diagnostic = [
            'timestamp' => date('Y-m-d H:i:s'),
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_active' => class_exists('WooCommerce'),
            'woocommerce_version' => class_exists('WooCommerce') ? WC()->version : 'N/A',
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'plugin_version' => '1.2',
            'consulta_instagram_plugin' => [
                'active' => class_exists('ConsultaInstagramPlugin'),
                'methods_available' => $this->analyze_instagram_plugin_methods()
            ],
            'cache_status' => [
                'recent_purchases' => get_transient('instagram_recent_purchases') !== false,
                'avatar_count' => $this->count_cached_avatars()
            ],
            'recent_orders' => []
        ];
        
        // Tentar buscar alguns pedidos para teste
        try {
            if (class_exists('WooCommerce')) {
                $args = [
                    'status' => ['processing', 'completed'],
                    'limit' => 5,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ];
                
                $orders = wc_get_orders($args);
                
                foreach ($orders as $order) {
                    $diagnostic['recent_orders'][] = [
                        'id' => $order->get_id(),
                        'status' => $order->get_status(),
                        'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                        'instagram_found' => !empty($this->get_instagram_from_order($order)),
                        'items_count' => count($order->get_items())
                    ];
                }
            }
        } catch (Exception $e) {
            $diagnostic['order_error'] = $e->getMessage();
        }
        
        return $diagnostic;
    }
    
    /**
     * NOVO MÉTODO: Analisar métodos disponíveis no plugin Instagram
     */
    private function analyze_instagram_plugin_methods() {
        if (!class_exists('ConsultaInstagramPlugin')) {
            return ['error' => 'Classe não encontrada'];
        }
        
        try {
            $reflection = new ReflectionClass('ConsultaInstagramPlugin');
            $methods = $reflection->getMethods();
            
            $public_methods = [];
            $private_methods = [];
            
            foreach ($methods as $method) {
                if ($method->isPublic() && !$method->isConstructor()) {
                    $public_methods[] = $method->getName();
                } elseif ($method->isPrivate()) {
                    $private_methods[] = $method->getName();
                }
            }
            
            return [
                'public_methods' => $public_methods,
                'private_methods' => $private_methods,
                'problematic_method' => in_array('consultar_instagram_rapidapi', $private_methods)
            ];
            
        } catch (ReflectionException $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    public function deactivation() {
        wp_clear_scheduled_hook('instagram_toasts_cleanup');
        delete_option('instagram_toasts_maintenance');
        delete_option('instagram_toasts_fallback_mode');
    }
}

// Instanciar o plugin
$instagramPurchaseToasts = new InstagramPurchaseToasts();

// Registro de ativação/desativação
register_activation_hook(__FILE__, [$instagramPurchaseToasts, 'init']);
register_deactivation_hook(__FILE__, [$instagramPurchaseToasts, 'deactivation']);

/**
 * NOVO: Hook para outros plugins fornecerem avatares
 * Outros plugins podem usar:
 * add_filter('instagram_purchase_toasts_avatar', 'minha_funcao_avatar', 10, 2);
 */
function instagram_toasts_get_avatar_hook($avatar_url, $username) {
    // Permitir que outros plugins forneçam URL do avatar
    return apply_filters('instagram_purchase_toasts_avatar', $avatar_url, $username);
}

/**
 * NOVO: Função pública para outros plugins integrarem
 * O plugin ConsultaInstagramPlugin pode chamar esta função:
 * instagram_toasts_set_avatar('username', 'https://avatar-url.jpg');
 */
function instagram_toasts_set_avatar($username, $avatar_url) {
    if (empty($username) || empty($avatar_url)) {
        return false;
    }
    
    $cache_key = 'instagram_avatar_' . md5($username);
    set_transient($cache_key, $avatar_url, 7 * DAY_IN_SECONDS);
    
    error_log("Instagram Toasts: Avatar definido via função pública para @{$username}");
    return true;
}

/**
 * NOVA FUNÇÃO: Para o plugin ConsultaInstagramPlugin se integrar
 * Adicione esta linha no plugin ConsultaInstagramPlugin após buscar dados:
 * if (function_exists('instagram_toasts_notify_avatar')) {
 *     instagram_toasts_notify_avatar($username, $profile_data['profile_pic_url']);
 * }
 */
function instagram_toasts_notify_avatar($username, $avatar_url) {
    return instagram_toasts_set_avatar($username, $avatar_url);
}

// Função de diagnóstico para acesso direto via URL (apenas para admins)
function instagram_toasts_diagnostic() {
    if (!current_user_can('manage_options') || !isset($_GET['diagnostic']) || $_GET['diagnostic'] !== 'true') {
        return;
    }
    
    global $instagramPurchaseToasts;
    $diagnostic = $instagramPurchaseToasts->system_diagnostic();
    
    header('Content-Type: application/json');
    echo json_encode($diagnostic, JSON_PRETTY_PRINT);
    exit;
}
// === Handler para heartbeat (usado apenas no systemDiagnostic do JS) ===
add_action('wp_ajax_heartbeat', function() {
    wp_send_json_success([
        'msg'  => 'pong',
        'time' => current_time('mysql'),
    ]);
});
add_action('wp_ajax_nopriv_heartbeat', function() {
    wp_send_json_success([
        'msg'  => 'pong',
        'time' => current_time('mysql'),
    ]);
});
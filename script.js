jQuery(document).ready(function($) {
    console.log('Instagram Purchase Toasts: Plugin carregado');
    
    if (window.instagramToastsInitialized) {
        console.log('Instagram Purchase Toasts: Já inicializado, ignorando');
        return;
    }
    window.instagramToastsInitialized = true;
    
    let toastQueue = [];
    let isShowingToast = false;
    let toastContainer = $('#instagram-purchase-toasts');
    let checkInterval;
    let processedOrders = new Set();
    let errorCount = 0;
    let maxErrors = 3;
    
    if (toastContainer.length === 0) {
        console.error('Instagram Purchase Toasts: Container não encontrado, criando dinamicamente');
        $('body').append('<div id="instagram-purchase-toasts"></div>');
        toastContainer = $('#instagram-purchase-toasts');
    } else {
        console.log('Instagram Purchase Toasts: Container encontrado');
    }
    
    if (typeof instagram_toasts === 'undefined') {
        console.error('Instagram Purchase Toasts: Variável global instagram_toasts não definida');
        return;
    }
    
    console.log('Instagram Purchase Toasts: Variáveis AJAX carregadas', instagram_toasts);
    
    window.instagramToastQueue = toastQueue;
    window.isShowingToast = isShowingToast;
    window.showNextToast = showNextToast;
    
    function systemDiagnostic() {
        console.log('=== DIAGNÓSTICO DO SISTEMA ===');
        console.log('WordPress AJAX URL:', instagram_toasts.ajax_url);
        console.log('Nonce disponível:', !!instagram_toasts.nonce);
        console.log('jQuery versão:', $().jquery);
        console.log('Container DOM:', toastContainer.length > 0 ? 'OK' : 'ERRO');
        console.log('Local Storage disponível:', typeof(Storage) !== 'undefined');
        
        $.ajax({
            url: instagram_toasts.ajax_url,
            type: 'POST',
            data: {
                action: 'heartbeat',
                _ajax_nonce: instagram_toasts.nonce
            },
            timeout: 5000,
            success: function(response) {
                console.log('Teste de AJAX: SUCESSO', response);
            },
            error: function(xhr, status, error) {
                console.error('Teste de AJAX: ERRO');
                console.error('Status HTTP:', xhr.status);
                console.error('Status Text:', xhr.statusText);
                console.error('Response Text:', xhr.responseText);
                console.error('Error:', error);
                showErrorNotification('Erro de conectividade detectado. Status: ' + xhr.status);
            }
        });
    }
    
    function showErrorNotification(message) {
        const errorToast = $(`
            <div class="instagram-purchase-toast error-toast">
                <div class="toast-content">
                    <div class="toast-username">⚠️ Sistema Instagram Toasts</div>
                    <div class="toast-product">${message}</div>
                    <div class="toast-time">Verifique o console do navegador</div>
                </div>
            </div>
        `);
        
        toastContainer.append(errorToast);
        
        setTimeout(() => errorToast.addClass('show'), 100);
        setTimeout(() => {
            errorToast.removeClass('show');
            setTimeout(() => errorToast.remove(), 500);
        }, 8000);
    }
    
    function loadProcessedOrders() {
        try {
            const stored = localStorage.getItem('instagram_processed_orders');
            if (stored) {
                const parsed = JSON.parse(stored);
                const now = Date.now();
                const oneDayAgo = now - (24 * 60 * 60 * 1000);
                
                Object.keys(parsed).forEach(orderId => {
                    if (parsed[orderId] > oneDayAgo) {
                        processedOrders.add(orderId);
                    }
                });
                
                console.log('Instagram Purchase Toasts: Carregados', processedOrders.size, 'pedidos já processados');
            }
        } catch (e) {
            console.error('Instagram Purchase Toasts: Erro ao carregar pedidos processados:', e);
        }
    }
    
    function saveProcessedOrders() {
        try {
            const now = Date.now();
            const toSave = {};
            processedOrders.forEach(orderId => {
                toSave[orderId] = now;
            });
            localStorage.setItem('instagram_processed_orders', JSON.stringify(toSave));
        } catch (e) {
            console.error('Instagram Purchase Toasts: Erro ao salvar pedidos processados:', e);
        }
    }
    
    function initToasts() {
        console.log('Instagram Purchase Toasts: Iniciando processo de toasts');
        systemDiagnostic();
        loadProcessedOrders();
        setTimeout(() => fetchRecentPurchases(), 2000);
        checkInterval = setInterval(fetchRecentPurchases, 180000);
    }
    
    function fetchRecentPurchases() {
        if (errorCount >= maxErrors) {
            console.error('Instagram Purchase Toasts: Muitos erros consecutivos, parando por 10 minutos');
            setTimeout(() => {
                errorCount = 0;
                console.log('Instagram Purchase Toasts: Resetando contador de erros');
            }, 600000);
            return;
        }
        
        console.log('Instagram Purchase Toasts: Buscando compras recentes (tentativa ' + (errorCount + 1) + ')');
        
        $.ajax({
            url: instagram_toasts.ajax_url,
            type: 'POST',
            data: {
                action: 'get_recent_purchases',
                nonce: instagram_toasts.nonce
            },
            timeout: 20000,
            beforeSend: function() {
                console.log('Instagram Purchase Toasts: Enviando requisição AJAX...');
            },
            success: function(response, textStatus, xhr) {
                console.log('Instagram Purchase Toasts: Resposta recebida');
                console.log('Status HTTP:', xhr.status);
                console.log('Response:', response);
                errorCount = 0;
                if (response && response.success) {
                    console.log('Instagram Purchase Toasts: ' + response.data.length + ' pedidos encontrados');
                    if (response.data.length > 0) processPurchases(response.data);
                    else console.log('Instagram Purchase Toasts: Nenhum pedido com Instagram encontrado');
                } else {
                    console.error('Instagram Purchase Toasts: Resposta de erro do servidor:', response);
                    if (response && response.data) {
                        showErrorNotification('Erro do servidor: ' + response.data);
                    }
                }
            },
            error: function(xhr, status, error) {
                errorCount++;
                console.error('=== ERRO DETALHADO ===');
                console.error('Status HTTP:', xhr.status);
                console.error('Status Text:', xhr.statusText);
                console.error('Ready State:', xhr.readyState);
                console.error('Response Text:', xhr.responseText);
                console.error('Status:', status);
                console.error('Error:', error);
                console.error('Erro #:', errorCount);
                if (xhr.status === 0) {
                    showErrorNotification('Erro de conectividade (Status 0)');
                } else if (xhr.status === 403) {
                    showErrorNotification('Erro de permissão (Status 403)');
                } else if (xhr.status === 500) {
                    showErrorNotification('Erro interno do servidor (Status 500)');
                } else if (xhr.status === 504) {
                    showErrorNotification('Timeout do servidor (Status 504)');
                } else if (status === 'timeout') {
                    showErrorNotification('Timeout da requisição (20s)');
                } else {
                    showErrorNotification('Erro não identificado (Status ' + xhr.status + ')');
                }
            },
            complete: function() {
                console.log('Instagram Purchase Toasts: Requisição AJAX finalizada');
            }
        });
    }
    
    function processPurchases(purchases) {
        console.log('Instagram Purchase Toasts: Processando ' + purchases.length + ' compras');
        let newPurchases = 0;
        purchases.forEach(purchase => {
            const orderId = purchase.order_id.toString();
            if (!processedOrders.has(orderId)) {
                console.log('Instagram Purchase Toasts: Novo pedido encontrado - ' + orderId, purchase);
                toastQueue.push(purchase);
                processedOrders.add(orderId);
                newPurchases++;
            }
        });
        if (newPurchases > 0) {
            console.log('Instagram Purchase Toasts: ' + newPurchases + ' novos pedidos adicionados à fila');
            saveProcessedOrders();
            if (!isShowingToast && toastQueue.length > 0) {
                console.log('Instagram Purchase Toasts: Iniciando exibição de toasts');
                showNextToast();
            }
        }
    }
    
    function showNextToast() {
        console.log('Instagram Purchase Toasts: Mostrando próximo toast. Fila: ' + toastQueue.length);
        if (toastQueue.length === 0) {
            console.log('Instagram Purchase Toasts: Fila vazia');
            isShowingToast = false;
            window.isShowingToast = false;
            return;
        }
        isShowingToast = true;
        window.isShowingToast = true;
        
        const purchase = toastQueue.shift();
        console.log('Instagram Purchase Toasts: Preparando toast para @' + purchase.instagram_username, purchase);
        
        // 🔹 Avatar corrigido: sempre usar URL segura
        let avatarUrl = 'default-avatar.png';
        if (purchase.hd_profile_pic_url_info && purchase.hd_profile_pic_url_info.url) {
            avatarUrl = purchase.hd_profile_pic_url_info.url;
        } else if (purchase.profile_pic_url) {
            avatarUrl = purchase.profile_pic_url;
        }
        
        const toast = $(`
            <div class="instagram-purchase-toast">
                <div class="toast-avatar">
                    <img src="${avatarUrl}" alt="${purchase.instagram_username}" onerror="handleAvatarError(this)">
                </div>
                <div class="toast-content">
                    <div class="toast-username">@${purchase.instagram_username}</div>
                    <div class="toast-product">
                        Comprou: <a href="${purchase.products[0].permalink}" target="_blank">${purchase.products[0].name}</a>
                    </div>
                    ${purchase.products[0].price ? `<div class="toast-price">R$ ${purchase.products[0].price}</div>` : ''}
                    <div class="toast-time">${formatTimeAgo(purchase.order_date)}</div>
                </div>
            </div>
        `);
        
        toastContainer.append(toast);
        setTimeout(() => toast.addClass('show'), 100);
        setTimeout(() => {
            toast.removeClass('show');
            setTimeout(() => {
                toast.remove();
                setTimeout(() => showNextToast(), 1000);
            }, 500);
        }, 8000);
    }
    
    window.handleAvatarError = function(img) {
        console.error('Instagram Purchase Toasts: Erro ao carregar avatar, usando fallback');
        img.onerror = null;
        const username = img.alt || 'U';
        img.src = 'https://via.placeholder.com/50x50/cccccc/999999?text=' + username.charAt(0).toUpperCase();
    };
    
    function formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);
        if (diffMins < 1) return 'Agora mesmo';
        if (diffMins < 60) return `Há ${diffMins} minuto${diffMins !== 1 ? 's' : ''}`;
        if (diffHours < 24) return `Há ${diffHours} hora${diffHours !== 1 ? 's' : ''}`;
        if (diffDays < 7) return `Há ${diffDays} dia${diffDays !== 1 ? 's' : ''}`;
        return date.toLocaleDateString('pt-BR');
    }
    
    $(window).on('beforeunload', function() {
        if (checkInterval) clearInterval(checkInterval);
    });
    
    window.debugInstagramToasts = {
        showTestToast: function(username, product) {
            const testData = {
                order_id: 'test-' + Date.now(),
                instagram_username: username || 'testuser',
                avatar_url: 'https://via.placeholder.com/50x50/E1306C/ffffff?text=IG',
                products: [{
                    name: product || 'Produto de Teste',
                    permalink: '#',
                    price: '99.90'
                }],
                order_date: new Date().toISOString(),
                customer_name: 'Cliente de Teste'
            };
            toastQueue.push(testData);
            if (!isShowingToast) showNextToast();
        },
        clearQueue: function() { toastQueue = []; },
        clearProcessedOrders: function() {
            processedOrders.clear();
            localStorage.removeItem('instagram_processed_orders');
        },
        getQueue: function() { return toastQueue; },
        getProcessedOrders: function() { return Array.from(processedOrders); },
        forceFetch: function() { errorCount = 0; fetchRecentPurchases(); },
        getErrorCount: function() { return errorCount; },
        resetErrors: function() { errorCount = 0; },
        systemDiagnostic: systemDiagnostic,
        testError: function() { showErrorNotification('Este é um teste de notificação de erro'); }
    };
    
    setTimeout(initToasts, 3000);
});

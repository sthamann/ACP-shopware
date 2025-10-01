// Dummy Agent Chat Interface - ACP Demo
// Simulates ChatGPT interacting with Shopware via ACP

let currentProduct = null;
let currentSession = null;
let paymentToken = null;
let cart = [];

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('userInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && e.target.value.trim()) {
            handleUserMessage(e.target.value.trim());
            e.target.value = '';
        }
    });

    // Start with welcome message
    setTimeout(() => {
        showWelcomeMessage();
    }, 500);
});

function showWelcomeMessage() {
    addMessage('user', 'Can you help me find a great housewarming gift? maybe a handmade, ceramic dinnerware set, in white and tan under $100');
    
    setTimeout(() => {
        const typingId = showTyping();
        setTimeout(() => {
            removeTyping(typingId);
            addMessage('assistant', 'Here\'s a curated selection of products from our Demo Merchant store:');
            setTimeout(showProducts, 300);
        }, 1500);
    }, 800);
}

function handleUserMessage(message) {
    addMessage('user', message);
    
    // Show typing indicator
    const typingId = showTyping();
    
    setTimeout(() => {
        removeTyping(typingId);
        
        // Simple keyword matching for demo
        if (message.toLowerCase().includes('dinnerware') || 
            message.toLowerCase().includes('ceramic') || 
            message.toLowerCase().includes('housewarming')) {
            showProducts();
        } else if (message.toLowerCase().includes('show') || 
                   message.toLowerCase().includes('find') ||
                   message.toLowerCase().includes('looking')) {
            addMessage('assistant', 'I found some beautiful handmade ceramic dinnerware sets for you:');
            setTimeout(showProducts, 300);
        } else {
            addMessage('assistant', 'I can help you find products! Try asking about "ceramic dinnerware" or "handmade gifts".');
        }
    }, 1000);
}

function addMessage(role, content) {
    const messagesContainer = document.getElementById('messages');
    const messageDiv = document.createElement('div');
    messageDiv.className = 'message';
    
    const avatar = document.createElement('div');
    avatar.className = `message-avatar ${role}-avatar`;
    avatar.textContent = role === 'user' ? '👤' : '🤖';
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    
    if (typeof content === 'string') {
        const textDiv = document.createElement('div');
        textDiv.className = 'message-text';
        textDiv.textContent = content;
        contentDiv.appendChild(textDiv);
    } else {
        contentDiv.appendChild(content);
    }
    
    messageDiv.appendChild(avatar);
    messageDiv.appendChild(contentDiv);
    messagesContainer.appendChild(messageDiv);
    
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function showTyping() {
    const messagesContainer = document.getElementById('messages');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message';
    typingDiv.id = 'typing-indicator';
    
    const avatar = document.createElement('div');
    avatar.className = 'message-avatar assistant-avatar';
    avatar.textContent = '🤖';
    
    const indicator = document.createElement('div');
    indicator.className = 'typing-indicator';
    indicator.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
    
    typingDiv.appendChild(avatar);
    typingDiv.appendChild(indicator);
    messagesContainer.appendChild(typingDiv);
    
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    
    return 'typing-indicator';
}

function removeTyping(id) {
    const element = document.getElementById(id);
    if (element) {
        element.remove();
    }
}

async function showProducts() {
    try {
        const response = await fetch('/api/agent/products');
        const data = await response.json();
        
        const productsContainer = document.createElement('div');
        const gridDiv = document.createElement('div');
        gridDiv.className = 'product-grid';
        
        data.products.forEach(product => {
            const card = createProductCard(product);
            gridDiv.appendChild(card);
        });
        
        productsContainer.appendChild(gridDiv);
        addMessage('assistant', productsContainer);
    } catch (error) {
        console.error('Error loading products:', error);
        addMessage('assistant', 'Sorry, I had trouble loading products. Please try again.');
    }
}

function createProductCard(product) {
    const card = document.createElement('div');
    card.className = 'product-card';
    card.onclick = () => showProductDetail(product);
    
    card.innerHTML = `
        <img src="${product.image}" alt="${product.name}" class="product-image">
        <div class="product-info">
            <div class="product-vendor">
                <div class="vendor-icon">E</div>
                <span class="vendor-name">${product.vendor}</span>
            </div>
            <div class="product-name">${product.name}</div>
            <div class="product-price">€${(product.price / 100).toFixed(2)}</div>
            <div class="instant-checkout">Instant checkout</div>
        </div>
    `;
    
    return card;
}

function showProductDetail(product) {
    currentProduct = product;
    const modal = document.getElementById('productModal');
    const modalBody = document.getElementById('modalBody');
    
    modalBody.innerHTML = `
        <div class="product-detail">
            <img src="${product.image}" alt="${product.name}" class="product-detail-image">
            
            <div class="vendor-badge">
                <div class="vendor-icon">E</div>
                <span>${product.vendor}</span>
            </div>
            
            <h2 class="product-detail-name">${product.name}</h2>
            <div class="product-detail-price">€${(product.price / 100).toFixed(2)}</div>
            
            <p class="product-detail-description">${product.description}</p>
            
            <div class="shipping-info">
                🚚 ${product.shipping}
            </div>
            
            <div class="quantity-selector">
                <button class="quantity-btn" onclick="changeQuantity(-1)">−</button>
                <span class="quantity-value" id="quantity">1</span>
                <button class="quantity-btn" onclick="changeQuantity(1)">+</button>
            </div>
            
            <button class="buy-button" onclick="startCheckout()">Buy</button>
        </div>
    `;
    
    modal.style.display = 'block';
}

function changeQuantity(delta) {
    const quantityEl = document.getElementById('quantity');
    let quantity = parseInt(quantityEl.textContent);
    quantity = Math.max(1, quantity + delta);
    quantityEl.textContent = quantity;
}

function closeProductModal() {
    document.getElementById('productModal').style.display = 'none';
}

function closeCheckoutModal() {
    document.getElementById('checkoutModal').style.display = 'none';
}

function closeCompletionModal() {
    document.getElementById('completionModal').style.display = 'none';
    closeCheckoutModal();
    closeProductModal();
    
    // Add confirmation message to chat
    setTimeout(() => {
        addMessage('assistant', 'Your order has been confirmed! You should receive a confirmation email shortly. Is there anything else I can help you with?');
    }, 500);
}

async function startCheckout() {
    const quantity = parseInt(document.getElementById('quantity').textContent);
    
    cart = [{
        id: currentProduct.id,
        name: currentProduct.name,
        price: currentProduct.price,
        quantity: quantity,
        image: currentProduct.image
    }];
    
    closeProductModal();
    showCheckoutModal();
}

function showCheckoutModal() {
    const modal = document.getElementById('checkoutModal');
    const body = document.getElementById('checkoutBody');
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const shipping = 0; // Free shipping
    const tax = Math.round(subtotal * 0.19); // 19% VAT
    const total = subtotal + shipping + tax;
    
    body.innerHTML = `
        <div class="checkout-step">
            <!-- Order Summary -->
            <div class="checkout-section">
                <h3 class="section-title">Your Order</h3>
                ${cart.map(item => `
                    <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                        <img src="${item.image}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px;">
                        <div style="flex: 1;">
                            <div style="font-weight: 500;">${item.name}</div>
                            <div style="color: #666; font-size: 14px;">Quantity: ${item.quantity}</div>
                        </div>
                        <div style="font-weight: 600;">€${((item.price * item.quantity) / 100).toFixed(2)}</div>
                    </div>
                `).join('')}
            </div>

            <!-- Shipping Address -->
            <div class="checkout-section">
                <h3 class="section-title">Shipping Address</h3>
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" id="addressName" class="form-input" value="Ada Lovelace">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" id="addressLine1" class="form-input" value="1234 Chat Road">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" id="addressCity" class="form-input" value="San Francisco">
                    </div>
                    <div class="form-group">
                        <label class="form-label">State</label>
                        <input type="text" id="addressState" class="form-input" value="CA">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Postal Code</label>
                        <input type="text" id="addressPostal" class="form-input" value="94131">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Country</label>
                        <input type="text" id="addressCountry" class="form-input" value="US">
                    </div>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="checkout-section">
                <h3 class="section-title">Payment Method</h3>
                <div class="payment-method">
                    <span class="payment-icon">💳</span>
                    <div>
                        <div style="font-weight: 500;">Visa ending in 1234</div>
                        <div style="color: #666; font-size: 13px;">Saved payment method</div>
                    </div>
                </div>
            </div>

            <!-- Total -->
            <div class="order-summary">
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>€${(subtotal / 100).toFixed(2)}</span>
                </div>
                <div class="summary-row">
                    <span>Shipping</span>
                    <span>Free</span>
                </div>
                <div class="summary-row">
                    <span>Estimated tax</span>
                    <span>€${(tax / 100).toFixed(2)}</span>
                </div>
                <div class="summary-row total">
                    <span>Total</span>
                    <span>€${(total / 100).toFixed(2)}</span>
                </div>
            </div>

            <button class="submit-button" onclick="processCheckout()" id="checkoutBtn">
                Pay Demo Merchant
            </button>

            <div style="font-size: 12px; color: #666; margin-top: 16px; text-align: center;">
                By continuing, you agree to the terms and privacy policy.
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
}

async function processCheckout() {
    const button = document.getElementById('checkoutBtn');
    button.disabled = true;
    button.textContent = 'Processing...';
    
    try {
        const address = {
            name: document.getElementById('addressName').value,
            line_one: document.getElementById('addressLine1').value,
            city: document.getElementById('addressCity').value,
            state: document.getElementById('addressState').value,
            country: document.getElementById('addressCountry').value,
            postal_code: document.getElementById('addressPostal').value
        };
        
        // Step 1: Create payment token via REAL Shopware ACP API
        console.log('🔄 Step 1: Creating payment token...');
        const tokenResponse = await fetch('/api/agent/create-payment-token', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                cardNumber: '4111111111111111',
                expMonth: '12',
                expYear: '2026',
                cvc: '123',
                cardBrand: 'visa',
                checkoutSessionId: 'checkout_' + Date.now()
            })
        });
        
        const tokenData = await tokenResponse.json();
        
        if (tokenData.error) {
            throw new Error('Payment token creation failed: ' + JSON.stringify(tokenData.error));
        }
        
        paymentToken = tokenData.id;
        console.log('✅ Payment token created:', paymentToken);
        console.log('   Token metadata:', tokenData.metadata);
        
        // Step 2: Create checkout session via REAL Shopware ACP API
        console.log('🔄 Step 2: Creating checkout session...');
        const sessionResponse = await fetch('/api/agent/checkout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                items: cart.map(item => ({ id: item.id, quantity: item.quantity })),
                fulfillmentAddress: address
            })
        });
        
        const sessionData = await sessionResponse.json();
        
        if (sessionData.error) {
            console.warn('⚠️ Session creation issue:', sessionData.error);
            // Continue with demo flow
            currentSession = 'demo_session_' + Date.now();
        } else {
            currentSession = sessionData.id;
            console.log('✅ Checkout session created:', currentSession);
            console.log('   Session status:', sessionData.status);
            console.log('   Currency:', sessionData.currency);
            console.log('   Totals:', sessionData.totals);
        }
        
        // Step 3: Complete checkout via REAL Shopware ACP API
        console.log('🔄 Step 3: Completing checkout...');
        
        const buyer = {
            first_name: address.name.split(' ')[0] || 'Demo',
            last_name: address.name.split(' ')[1] || 'User',
            email: 'demo@example.com',
            phone_number: '+11234567890'
        };
        
        const completeResponse = await fetch('/api/agent/complete-checkout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                sessionId: currentSession,
                buyer: buyer,
                paymentToken: paymentToken
            })
        });
        
        const completeData = await completeResponse.json();
        
        if (completeData.error) {
            console.warn('⚠️ Completion issue:', completeData.error);
            // Show completion screen anyway for demo
        } else {
            console.log('✅ Order completed!');
            console.log('   Order ID:', completeData.order?.id);
            console.log('   Permalink:', completeData.order?.permalink_url);
        }
        
        // Step 4: Show completion screen
        setTimeout(() => {
            showCompletionScreen(completeData);
        }, 500);
        
    } catch (error) {
        console.error('❌ Checkout error:', error);
        button.disabled = false;
        button.textContent = 'Pay Demo Merchant';
        
        // Show error in UI
        alert('Checkout error: ' + error.message + '\n\nCheck browser console for details.');
    }
}

function showCompletionScreen(orderData) {
    const modal = document.getElementById('completionModal');
    const body = document.getElementById('completionBody');
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const tax = Math.round(subtotal * 0.19);
    const total = subtotal + tax;
    
    const estimatedDelivery = new Date();
    estimatedDelivery.setDate(estimatedDelivery.getDate() + 5);
    const deliveryDate = estimatedDelivery.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    
    const orderId = orderData?.order?.id || 'Processing...';
    const orderUrl = orderData?.order?.permalink_url || '';
    
    body.innerHTML = `
        <div class="completion-screen">
            <div class="success-icon">✓</div>
            <h2 class="completion-title">Purchase complete</h2>
            <p class="completion-subtitle">Your order will arrive ${deliveryDate}</p>
            
            <div class="order-details">
                ${cart.map(item => `
                    <div style="display: flex; gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #e5e5e5;">
                        <img src="${item.image}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px;">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; margin-bottom: 4px;">${item.name}</div>
                            <div style="color: #666;">Quantity: ${item.quantity}</div>
                            <div style="color: #666; font-size: 14px; margin-top: 4px;">Estimated delivery: ${deliveryDate}</div>
                        </div>
                    </div>
                `).join('')}
                
                <div class="detail-row" style="margin-top: 16px;">
                    <span style="color: #666;">Sold by</span>
                    <span style="font-weight: 500;">Demo Merchant</span>
                </div>
                <div class="detail-row">
                    <span style="color: #666;">Order ID</span>
                    <span style="font-weight: 500; font-family: monospace; font-size: 12px;">${orderId}</span>
                </div>
                <div class="detail-row">
                    <span style="color: #666;">Paid Demo Merchant</span>
                    <span style="font-weight: 600;">€${(total / 100).toFixed(2)}</span>
                </div>
            </div>
            
            ${orderUrl ? `<a href="${orderUrl}" target="_blank" class="submit-button" style="display: block; text-decoration: none; text-align: center; margin-bottom: 12px;">View Order in Shopware</a>` : ''}
            
            <button class="submit-button" onclick="viewOrderDetails()">View details</button>
            
            <div style="margin-top: 20px; padding: 16px; background: #f9f9f9; border-radius: 12px; font-size: 14px; color: #666; text-align: left;">
                🎉 Demo Merchant confirmed your order! You'll get a confirmation email soon. The order has been created in Shopware Admin. You can view your order details anytime.
            </div>
        </div>
    `;
    
    modal.style.display = 'block';
}

function viewOrderDetails() {
    closeCompletionModal();
    
    setTimeout(() => {
        const orderDetails = `
Order Details:
━━━━━━━━━━━━━━━━━━━━━━━━
${cart.map(item => `
${item.name}
Quantity: ${item.quantity}
Price: €${((item.price * item.quantity) / 100).toFixed(2)}
`).join('\n')}
━━━━━━━━━━━━━━━━━━━━━━━━
Payment Token: ${paymentToken}
${currentSession ? `Session ID: ${currentSession}` : ''}

Status: ✅ Completed
Delivery: In 3-5 business days
        `.trim();
        
        addMessage('assistant', orderDetails);
    }, 300);
}

function closeProductModal() {
    document.getElementById('productModal').style.display = 'none';
}

function closeCheckoutModal() {
    document.getElementById('checkoutModal').style.display = 'none';
}

function closeCompletionModal() {
    document.getElementById('completionModal').style.display = 'none';
    closeCheckoutModal();
    closeProductModal();
    
    setTimeout(() => {
        addMessage('assistant', '✅ Your order has been confirmed! You\'ll receive a confirmation email soon. Is there anything else I can help you with?');
    }, 500);
}


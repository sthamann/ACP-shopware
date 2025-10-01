# 🚀 Quick Start Guide - ACP Dummy Agent Demo

## Prerequisites Check

✅ Shopware Docker container running
✅ ACP plugin installed  
✅ Port 3000 available

## Start the Demo

### Step 1: Start Shopware (if not running)

```bash
cd ../tests
./docker-start.sh start
```

### Step 2: Install Dependencies

```bash
cd /path/to/dummy-agent
npm install
```

### Step 3: Start the Dummy Agent

**Demo Mode (default):**
```bash
npm start
# or
./start-demo.sh
```

**Production Mode (PayPal Sandbox):**
```bash
PRODUCTIVE_MODE=true npm start
# or
./start-productive.sh
```

You should see:

```
========================================
🤖 ACP Dummy Agent Chat Interface
========================================
Server running on: http://localhost:3000
Shopware ACP API: http://localhost:80
API Version: 2025-09-29
Mode: 🔴 PRODUCTION (PayPal)  [or 🔵 DEMO]
========================================

Open http://localhost:3000 in your browser to start!
```

### Step 4: Open in Browser

```
http://localhost:3000
```

## Using the Demo

### 1. Start a Conversation

Type in the chat:
```
Can you help me find a great housewarming gift? 
maybe a handmade ceramic dinnerware set
```

### 2. Browse Products

The agent will show you product cards. Click on any product to see details.

### 3. Start Checkout

Click the "Buy" button on a product.

### 4. Review and Pay

- Review order summary
- Shipping address is pre-filled
- Click "Pay Etsy" to complete

### 5. Order Confirmation

You'll see:
- ✅ "Purchase complete" screen
- Order details
- Delivery estimate
- Confirmation in chat

## What's Happening Behind the Scenes

```
1. User asks → Agent responds with products
2. User clicks product → Modal shows details
3. User clicks "Buy" → Checkout modal opens
4. User clicks "Pay" →
   a) POST /api/agentic_commerce/delegate_payment (creates token)
   b) POST /api/checkout_sessions (creates session)
   c) POST /api/checkout_sessions/{id}/complete (completes order)
5. Shows completion screen
```

## Console Logs

Open browser console (F12) to see:
- ✓ Payment token created: `vt_paypal_xxx` (Production) or `vt_card_xxx` (Demo)
- ✓ Checkout session created: checkout_xxx
- Real API responses from Shopware

## Test the Full Flow

Watch the complete ACP integration in action:

1. **Product Discovery** - Conversational product search
2. **Product Selection** - Native UI product cards
3. **Checkout** - Embedded checkout experience
4. **Payment Delegation** - Real ACP token creation
5. **Session Management** - Real Shopware cart
6. **Order Completion** - Real order in Shopware
7. **Confirmation** - Native completion UI

## Stopping the Demo

Press `Ctrl+C` in the terminal where `npm start` is running.

Or:

```bash
lsof -ti:3000 | xargs kill
```

## Troubleshooting

### Server won't start - Port 3000 in use

```bash
# Find what's using port 3000
lsof -i:3000

# Kill it
lsof -ti:3000 | xargs kill

# Try again
npm start
```

### Can't connect to Shopware

```bash
# Check Shopware is running
curl http://localhost:80/api/_info/version

# Restart Shopware if needed
cd ../tests
./docker-start.sh restart
```

### Products show error

This is expected! The demo uses mock product IDs. The important part is:
- ✅ Payment tokens are created (real)
- ✅ API calls work (real)
- ✅ UI/UX flow is demonstrated

## Success Criteria

You know it's working when:

- [x] Chat interface loads at http://localhost:3000
- [x] You can type messages
- [x] Products appear when you ask
- [x] Product modal opens smoothly
- [x] Checkout modal slides up
- [x] Browser console shows "✓ Payment token created"
- [x] Completion screen appears with checkmark
- [x] Confirmation message in chat

## Next Steps

After seeing the demo:

1. **Customize Products** - Edit `server.js` to use real Shopware products
2. **Enhance AI** - Add more conversational patterns
3. **Style Tweaks** - Modify `styles.css` to match your brand
4. **Add Features** - Implement search, filters, recommendations

## Demo Features

This demo showcases:

✨ **ACP Protocol Integration**
- Real payment token delegation
- Real checkout session management
- Real order completion

✨ **ChatGPT-Style UI**
- Clean, modern interface
- Smooth animations
- Mobile-responsive
- Native-feeling modals

✨ **E-commerce Flow**
- Product discovery
- Product details
- Cart management
- Checkout process
- Order confirmation

**Enjoy the demo!** 🎉


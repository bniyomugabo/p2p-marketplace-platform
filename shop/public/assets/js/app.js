// /public/assets/js/app.js
// Main JavaScript for MarketHub Marketplace

/**
 * DOM Ready Event Handler
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initQuickAddButtons();
    initCartBadge();
    initSearchAutocomplete();
    initProductFilters();
    initCheckoutForm();
    initQuantitySelectors();
    initVariantSwitcher();
    initMobileMenu();
    
    // Update cart badge on page load
    updateCartBadge();
});

/**
 * Cart Functions
 */

// Add to cart function
window.addToCart = async function(variantId, quantity, productName, variantName, price) {
    try {
        const response = await fetch('../src/api/update_cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'add',
                variant_id: variantId,
                quantity: quantity,
                product_name: productName,
                variant_name: variantName,
                price: price
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            updateCartBadge(result.cart_count);
            showNotification('Product added to cart!', 'success');
            return true;
        } else {
            showNotification(result.message || 'Failed to add to cart', 'error');
            return false;
        }
    } catch (error) {
        console.error('Add to cart error:', error);
        showNotification('Network error. Please try again.', 'error');
        return false;
    }
};

// Update cart item quantity
window.updateCartQuantity = async function(variantId, quantity) {
    try {
        const response = await fetch('../src/api/update_cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'update',
                variant_id: variantId,
                quantity: quantity
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            updateCartBadge(result.cart_count);
            return true;
        }
        return false;
    } catch (error) {
        console.error('Update cart error:', error);
        return false;
    }
};

// Remove from cart
window.removeFromCart = async function(variantId) {
    try {
        const response = await fetch('../src/api/update_cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'remove',
                variant_id: variantId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            updateCartBadge(result.cart_count);
            if (window.location.pathname.includes('cart.php')) {
                location.reload();
            }
            return true;
        }
        return false;
    } catch (error) {
        console.error('Remove from cart error:', error);
        return false;
    }
};

// Update cart badge display

function updateCartBadge(count = null) {
    const badge = document.getElementById('cart-badge');
    if (!badge) return;
    
    if (count === null) {
        // Fetch current cart count
        fetch('../src/api/get_cart.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const itemCount = data.cart_count;
                    if (itemCount > 0) {
                        badge.textContent = itemCount;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(error => console.error('Error fetching cart count:', error));
    } else {
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }
}

// Build filter URL with JavaScript

function buildFilterUrlWithJs(paramName, paramValue) {
    // Instantiate a URL reader configuration mapped directly to browser window location state
    const currentUrl = new URL(window.location.href);
    
    if (paramValue) {
        currentUrl.searchParams.set(paramName, paramValue);
    } else {
        currentUrl.searchParams.delete(paramName);
    }
    
    // Always clear pagination offset index flags when switching filters or sort criteria
    currentUrl.searchParams.delete('page');
    
    return currentUrl.toString();
}

/**
 * UI Components
 */

// Initialize quick add buttons on product cards
function initQuickAddButtons() {
    const buttons = document.querySelectorAll('.quick-add');
    
    buttons.forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            const productId = this.dataset.productId;
            
            // Show loading state
            const originalText = this.textContent;
            this.textContent = 'Adding...';
            this.disabled = true;
            
            try {
                // Fetch product details
                const response = await fetch(`../src/api/get_product_detail.php?id=${productId}`);
                const data = await response.json();
                
                if (data.success && data.product.variants && data.product.variants.length > 0) {
                    const variant = data.product.variants[0];
                    
                    await addToCart(
                        variant.id,
                        1,
                        data.product.product_name,
                        variant.variant_name || 'Standard',
                        variant.selling_price
                    );
                } else {
                    showNotification('Product not available', 'error');
                }
            } catch (error) {
                console.error('Quick add error:', error);
                showNotification('Error adding product', 'error');
            } finally {
                this.textContent = originalText;
                this.disabled = false;
            }
        });
    });
}

// Initialize cart badge on page load
function initCartBadge() {
    updateCartBadge();
}

// Initialize search autocomplete
function initSearchAutocomplete() {
    const searchInput = document.querySelector('.search-form input');
    if (!searchInput) return;
    
    let debounceTimer;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            removeAutocompleteResults();
            return;
        }
        
        debounceTimer = setTimeout(() => {
            fetchAutocompleteResults(query);
        }, 300);
    });
    
    // Close autocomplete when clicking outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target)) {
            removeAutocompleteResults();
        }
    });
}

async function fetchAutocompleteResults(query) {
    try {
        const response = await fetch(`../src/api/search_autocomplete.php?q=${encodeURIComponent(query)}`);
        const data = await response.json();
        
        if (data.success && data.results.length > 0) {
            displayAutocompleteResults(data.results);
        } else {
            removeAutocompleteResults();
        }
    } catch (error) {
        console.error('Autocomplete error:', error);
    }
}

function displayAutocompleteResults(results) {
    removeAutocompleteResults();
    
    const searchForm = document.querySelector('.search-form');
    if (!searchForm) return;
    
    const container = document.createElement('div');
    container.className = 'autocomplete-results';
    
    results.forEach(result => {
        const item = document.createElement('a');
        item.href = `/product.php?id=${result.id}`;
        item.className = 'autocomplete-item';
        item.innerHTML = `
            <div class="autocomplete-image">
                <img src="${result.image || 'img/main.svg'}" alt="${escapeHtml(result.product_name)}">
            </div>
            <div class="autocomplete-info">
                <div class="autocomplete-title">${escapeHtml(result.product_name)}</div>
                <div class="autocomplete-price">${result.price || '0'} RWF</div>
            </div>
        `;
        container.appendChild(item);
    });
    
    searchForm.appendChild(container);
}

function removeAutocompleteResults() {
    const existing = document.querySelector('.autocomplete-results');
    if (existing) existing.remove();
}

// Initialize product filters
function initProductFilters() {
    const filterSelects = document.querySelectorAll('.filter-select');
    
    filterSelects.forEach(select => {
        select.addEventListener('change', function() {
            const url = new URL(window.location.href);
            url.searchParams.set(this.name, this.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    });
}

// Initialize checkout form validation
function initCheckoutForm() {
    const form = document.getElementById('checkout-form');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate form
        if (!validateCheckoutForm()) {
            return;
        }
        
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Processing...';
        submitBtn.disabled = true;
        
        try {
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            
            const response = await fetch('../src/api/process_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                window.location.href = `/order_confirmation.php?invoice=${result.invoice_number}`;
            } else {
                showNotification(result.message || 'Order failed. Please try again.', 'error');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }
        } catch (error) {
            console.error('Checkout error:', error);
            showNotification('Network error. Please try again.', 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    });
}

function validateCheckoutForm() {
    const requiredFields = ['full_name', 'email', 'phone', 'address', 'city'];
    let isValid = true;
    
    requiredFields.forEach(field => {
        const input = document.querySelector(`[name="${field}"]`);
        if (input && !input.value.trim()) {
            input.classList.add('error');
            isValid = false;
        } else if (input) {
            input.classList.remove('error');
        }
    });
    
    // Validate email format
    const emailInput = document.querySelector('[name="email"]');
    if (emailInput && emailInput.value.trim()) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailInput.value.trim())) {
            emailInput.classList.add('error');
            showNotification('Please enter a valid email address', 'error');
            isValid = false;
        }
    }
    
    // Validate phone (basic)
    const phoneInput = document.querySelector('[name="phone"]');
    if (phoneInput && phoneInput.value.trim()) {
        const phoneRegex = /^[0-9+\-\s()]{8,20}$/;
        if (!phoneRegex.test(phoneInput.value.trim())) {
            phoneInput.classList.add('error');
            showNotification('Please enter a valid phone number', 'error');
            isValid = false;
        }
    }
    
    if (!isValid) {
        showNotification('Please fill in all required fields correctly', 'error');
    }
    
    return isValid;
}

// Initialize quantity selectors
function initQuantitySelectors() {
    const selectors = document.querySelectorAll('.quantity-selector input');
    
    selectors.forEach(input => {
        input.addEventListener('change', function() {
            let value = parseInt(this.value);
            const min = parseInt(this.min) || 1;
            const max = parseInt(this.max) || 99;
            
            if (isNaN(value) || value < min) {
                this.value = min;
            } else if (value > max) {
                this.value = max;
            }
        });
    });
}

// Initialize variant switcher on product page
function initVariantSwitcher() {
    const variantSelect = document.getElementById('variant-select');
    if (!variantSelect) return;
    
    variantSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const price = selectedOption.dataset.price;
        const inStock = selectedOption.dataset.instock === 'true';
        const stockQty = parseInt(selectedOption.dataset.stock);
        const quantityInput = document.getElementById('quantity');
        const stockStatus = document.getElementById('stock-status');
        const priceDisplay = document.querySelector('.price');
        
        // Update price display
        if (priceDisplay) {
            priceDisplay.textContent = formatCurrency(parseFloat(price));
        }
        
        // Update stock status
        if (stockStatus) {
            const quantity = parseInt(quantityInput?.value || 1);
            if (!inStock) {
                stockStatus.innerHTML = '<span class="out-of-stock">Out of Stock</span>';
                disableAddToCart(true);
            } else if (stockQty < quantity) {
                stockStatus.innerHTML = `<span class="low-stock">Only ${stockQty} units available</span>`;
                disableAddToCart(true);
            } else {
                stockStatus.innerHTML = '<span class="in-stock">In Stock</span>';
                disableAddToCart(false);
            }
        }
    });
}

function disableAddToCart(disabled) {
    const btn = document.getElementById('add-to-cart-btn');
    if (btn) {
        btn.disabled = disabled;
    }
}

// Initialize mobile menu
function initMobileMenu() {
    const mobileBtn = document.querySelector('.mobile-menu-btn');
    if (!mobileBtn) return;
    
    const navLinks = document.querySelector('.nav-links');
    
    mobileBtn.addEventListener('click', function() {
        navLinks.classList.toggle('active');
        this.classList.toggle('active');
    });
}

/**
 * Helper Functions
 */

// Show notification
window.showNotification = function(message, type = 'info') {
    // Remove existing notifications
    const existing = document.querySelectorAll('.notification');
    existing.forEach(notification => notification.remove());
    
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
};

// Format currency
window.formatCurrency = function(amount, currency = 'RWF') {
    return new Intl.NumberFormat('en-RW', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
};

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Debounce function for performance
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Load more products (infinite scroll)
let isLoading = false;
let currentPage = 1;
let hasMorePages = true;

window.initInfiniteScroll = function(totalPages) {
    if (totalPages <= 1) return;
    
    window.addEventListener('scroll', debounce(async function() {
        if (isLoading || !hasMorePages) return;
        
        const scrollPosition = window.innerHeight + window.scrollY;
        const documentHeight = document.documentElement.scrollHeight;
        
        if (scrollPosition >= documentHeight - 500) {
            currentPage++;
            
            if (currentPage > totalPages) {
                hasMorePages = false;
                return;
            }
            
            isLoading = true;
            await loadMoreProducts();
            isLoading = false;
        }
    }, 200));
};

async function loadMoreProducts() {
    const loader = document.querySelector('.loading-spinner');
    if (loader) loader.style.display = 'block';
    
    try {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('page', currentPage);
        urlParams.set('ajax', '1');
        
        const response = await fetch(`../src/api/get_products.php?${urlParams.toString()}`);
        const data = await response.json();
        
        if (data.success && data.data.products.length > 0) {
            const container = document.querySelector('.products-grid');
            if (container) {
                data.data.products.forEach(product => {
                    const productCard = createProductCard(product);
                    container.appendChild(productCard);
                });
            }
        }
    } catch (error) {
        console.error('Load more error:', error);
    } finally {
        if (loader) loader.style.display = 'none';
    }
}

function createProductCard(product) {
    const div = document.createElement('div');
    div.className = 'product-card';
    div.innerHTML = `
        <a href="/product.php?id=${product.id}">
            <div class="product-image">
                <img src="${escapeHtml(product.image_url || 'img/main.svg')}" 
                     alt="${escapeHtml(product.product_name)}">
            </div>
            <div class="product-info">
                <div class="vendor-name">${escapeHtml(product.company_name)}</div>
                <h3 class="product-title">${escapeHtml(product.product_name)}</h3>
                <div class="product-price">${product.price_range || formatCurrency(product.min_price)}</div>
            </div>
        </a>
        <button class="quick-add" data-product-id="${product.id}">Quick Add</button>
    `;
    
    // Add event listener to new button
    const quickAddBtn = div.querySelector('.quick-add');
    quickAddBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        const response = await fetch(`../src/api/get_product_detail.php?id=${product.id}`);
        const data = await response.json();
        if (data.success && data.product.variants && data.product.variants.length > 0) {
            const variant = data.product.variants[0];
            await addToCart(variant.id, 1, product.product_name, variant.variant_name || 'Standard', variant.selling_price);
        }
    });
    
    return div;
}

// Wishlist functionality
let wishlist = [];

window.toggleWishlist = function(productId) {
    const index = wishlist.indexOf(productId);
    if (index === -1) {
        wishlist.push(productId);
        showNotification('Added to wishlist', 'success');
    } else {
        wishlist.splice(index, 1);
        showNotification('Removed from wishlist', 'info');
    }
    
    // Save to localStorage
    localStorage.setItem('wishlist', JSON.stringify(wishlist));
    
    // Update UI
    updateWishlistButtons();
};

function updateWishlistButtons() {
    document.querySelectorAll('.wishlist-btn').forEach(btn => {
        const productId = parseInt(btn.dataset.productId);
        if (wishlist.includes(productId)) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

// Load wishlist from localStorage
function loadWishlist() {
    const saved = localStorage.getItem('wishlist');
    if (saved) {
        wishlist = JSON.parse(saved);
        updateWishlistButtons();
    }
}

// Compare products functionality
let compareList = [];

window.toggleCompare = function(productId) {
    const index = compareList.indexOf(productId);
    if (index === -1) {
        if (compareList.length >= 4) {
            showNotification('You can compare up to 4 products', 'error');
            return;
        }
        compareList.push(productId);
        showNotification('Added to compare', 'success');
    } else {
        compareList.splice(index, 1);
        showNotification('Removed from compare', 'info');
    }
    
    localStorage.setItem('compareList', JSON.stringify(compareList));
    updateCompareCount();
};

function updateCompareCount() {
    const count = compareList.length;
    const badge = document.querySelector('.compare-badge');
    if (badge) {
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }
}

function loadCompareList() {
    const saved = localStorage.getItem('compareList');
    if (saved) {
        compareList = JSON.parse(saved);
        updateCompareCount();
    }
}

// Price filter slider
function initPriceSlider() {
    const slider = document.getElementById('price-slider');
    if (!slider) return;
    
    noUiSlider.create(slider, {
        start: [0, 100000],
        connect: true,
        range: {
            'min': 0,
            'max': 500000
        },
        format: {
            to: value => Math.round(value),
            from: value => Number(value)
        }
    });
    
    slider.noUiSlider.on('change', function(values) {
        const url = new URL(window.location.href);
        url.searchParams.set('min_price', values[0]);
        url.searchParams.set('max_price', values[1]);
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    });
}

// Initialize all components on page load
document.addEventListener('DOMContentLoaded', function() {
    loadWishlist();
    loadCompareList();
    initPriceSlider();
});

// Export functions for global use
window.updateCartBadge = updateCartBadge;
window.showNotification = showNotification;
window.formatCurrency = formatCurrency;
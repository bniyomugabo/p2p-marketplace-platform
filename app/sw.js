// sw.js - Service Worker for SATI ERP
const CACHE_NAME = 'sati-erp-v1.0.0';
const STATIC_CACHE = 'sati-static-v1';
const DYNAMIC_CACHE = 'sati-dynamic-v1';
const API_CACHE = 'sati-api-v1';

// Assets to cache immediately
const STATIC_ASSETS = [
  './',
  './index.php',
  './offline.php',
  './manifest.json',
  './assets/css/style.css',
  './assets/css/dashboard.css',
  './assets/js/main.js'
];

// Install event
self.addEventListener('install', event => {
  console.log('[SW] Installing...');
  
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => {
        console.log('[SW] Caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => self.skipWaiting())
  );
});

// Activate event
self.addEventListener('activate', event => {
  console.log('[SW] Activating...');
  
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(key => key !== STATIC_CACHE && key !== DYNAMIC_CACHE && key !== API_CACHE)
          .map(key => {
            console.log('[SW] Removing old cache:', key);
            return caches.delete(key);
          })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  
  // Skip non-GET requests
  if (request.method !== 'GET') {
    event.respondWith(fetch(request));
    return;
  }
  
  // Handle API requests (Network First)
  if (url.pathname.includes('/api/')) {
    event.respondWith(networkFirstAPI(request));
    return;
  }
  
  // Handle static assets (Cache First)
  if (url.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|ico|webp|woff|woff2|ttf)$/)) {
    event.respondWith(cacheFirst(request));
    return;
  }
  
  // Handle HTML pages (Network First with offline fallback)
  if (request.headers.get('accept')?.includes('text/html')) {
    event.respondWith(networkFirstWithOffline(request));
    return;
  }
  
  // Default: Network First
  event.respondWith(networkFirst(request));
});

// Cache First Strategy
async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request);
  
  if (cached) {
    console.log('[SW] Cache hit:', request.url);
    return cached;
  }
  
  try {
    const response = await fetch(request);
    if (response.status === 200) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    console.error('[SW] Fetch failed:', error);
    return new Response('Network error', { status: 404 });
  }
}

// Network First Strategy
async function networkFirst(request) {
  const cache = await caches.open(DYNAMIC_CACHE);
  
  try {
    const response = await fetch(request);
    
    if (response.status === 200) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    console.log('[SW] Network failed, using cache:', request.url);
    const cached = await cache.match(request);
    
    if (cached) {
      return cached;
    }
    
    return new Response('Offline - Check your connection', { status: 503 });
  }
}

// Network First for API
async function networkFirstAPI(request) {
  const cache = await caches.open(API_CACHE);
  
  try {
    const response = await fetch(request.clone());
    
    if (response.status === 200) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    console.log('[SW] API network failed, using cache:', request.url);
    const cached = await cache.match(request);
    
    if (cached) {
      return cached;
    }
    
    // Return offline JSON response
    return new Response(JSON.stringify({ 
      success: false, 
      error: 'Offline mode',
      message: 'You are offline. Please check your connection.'
    }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

// Network First with Offline Fallback
async function networkFirstWithOffline(request) {
  try {
    const response = await fetch(request);
    
    if (response.status === 200) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    console.log('[SW] Offline fallback for:', request.url);
    const cached = await caches.match(request);
    
    if (cached) {
      return cached;
    }
    
    return caches.match('./offline.php');
  }
}

// Push Notification Event
self.addEventListener('push', event => {
  console.log('[SW] Push received:', event);
  
  let data = {
    title: 'SATI ERP',
    body: 'You have a new notification',
    icon: './assets/icons/icon-192x192.png',
    badge: './assets/icons/badge-icon.png',
    url: '/'
  };
  
  if (event.data) {
    try {
      const parsed = event.data.json();
      data = { ...data, ...parsed };
    } catch (error) {
      data.body = event.data.text();
    }
  }
  
  const options = {
    body: data.body,
    icon: data.icon,
    badge: data.badge,
    vibrate: [200, 100, 200],
    data: {
      url: data.url,
      dateOfArrival: Date.now()
    },
    actions: [
      {
        action: 'view',
        title: 'View',
        icon: './assets/icons/view-icon.png'
      },
      {
        action: 'dismiss',
        title: 'Dismiss',
        icon: './assets/icons/close-icon.png'
      }
    ]
  };
  
  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// Notification Click Event
self.addEventListener('notificationclick', event => {
  console.log('[SW] Notification click:', event);
  
  event.notification.close();
  
  const urlToOpen = event.notification.data?.url || '/';
  
  event.waitUntil(
    clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then(windowClients => {
      for (let client of windowClients) {
        if (client.url === urlToOpen && 'focus' in client) {
          return client.focus();
        }
      }
      
      if (clients.openWindow) {
        return clients.openWindow(urlToOpen);
      }
    })
  );
});
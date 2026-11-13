// Netzwerk-Icons (viewBox 0 0 48 48). Stroke/Fill über currentColor bzw. Attribute.
// Werden im Gerätebaum UND im Map-Editor verwendet (inline SVG, damit Export als SVG/PNG funktioniert).
window.RM_ICONS = (() => {
  const S = 'fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"';
  const F = 'fill="currentColor"';
  const icons = {
    server: { label: 'Server', svg: `<rect x="10" y="5" width="28" height="38" rx="3" ${S}/><path d="M10 17h28M10 29h28" ${S}/><circle cx="16" cy="11" r="1.8" ${F}/><circle cx="16" cy="23" r="1.8" ${F}/><circle cx="16" cy="35" r="1.8" ${F}/><path d="M24 11h9M24 23h9M24 35h9" ${S}/>` },
    router: { label: 'Router', svg: `<ellipse cx="24" cy="30" rx="18" ry="8" ${S}/><path d="M6 30v-6c0-4.4 8-8 18-8s18 3.6 18 8v6" ${S}/><path d="M17 22l-4-4m0 0h4m-4 0v4M31 22l4-4m0 0h-4m4 0v4M24 24v-8m0 0-3 3m3-3 3 3" ${S}/>` },
    switch: { label: 'Switch', svg: `<rect x="4" y="15" width="40" height="18" rx="3" ${S}/><path d="M10 21h3M16 21h3M22 21h3M28 21h3M10 27h3M16 27h3M22 27h3M28 27h3" ${S}/><circle cx="38" cy="24" r="2" ${F}/>` },
    firewall: { label: 'Firewall', svg: `<rect x="5" y="8" width="38" height="32" rx="2" ${S}/><path d="M5 18.7h38M5 29.3h38M17 8v10.7M31 8v10.7M11 18.7v10.6M24 18.7v10.6M37 18.7v10.6M17 29.3V40M31 29.3V40" ${S}/>` },
    pc: { label: 'PC / Client', svg: `<rect x="5" y="7" width="38" height="26" rx="2.5" ${S}/><path d="M18 41h12M24 33v8" ${S}/>` },
    laptop: { label: 'Laptop', svg: `<rect x="9" y="10" width="30" height="21" rx="2" ${S}/><path d="M4 37h40l-4-6H8z" ${S}/>` },
    printer: { label: 'Drucker', svg: `<path d="M14 18V6h20v12" ${S}/><rect x="6" y="18" width="36" height="16" rx="3" ${S}/><path d="M14 28h20v14H14z" ${S}/><circle cx="36" cy="23" r="1.6" ${F}/>` },
    wifi: { label: 'WLAN-AP', svg: `<path d="M8 20a23 23 0 0 1 32 0M13 26a15.5 15.5 0 0 1 22 0M18.5 32a8 8 0 0 1 11 0" ${S}/><circle cx="24" cy="38" r="2.6" ${F}/>` },
    database: { label: 'Datenbank', svg: `<ellipse cx="24" cy="10" rx="15" ry="5" ${S}/><path d="M9 10v28c0 2.8 6.7 5 15 5s15-2.2 15-5V10M9 19c0 2.8 6.7 5 15 5s15-2.2 15-5M9 28.5c0 2.8 6.7 5 15 5s15-2.2 15-5" ${S}/>` },
    cloud: { label: 'Cloud', svg: `<path d="M14 38h22a9 9 0 0 0 1-18 12 12 0 0 0-23-2 10 10 0 0 0 0 20z" ${S}/>` },
    internet: { label: 'Internet', svg: `<circle cx="24" cy="24" r="18" ${S}/><path d="M6 24h36M24 6c5 5 7.5 11 7.5 18S29 37 24 42c-5-5-7.5-11-7.5-18S19 11 24 6z" ${S}/>` },
    web: { label: 'Webseite', svg: `<rect x="5" y="8" width="38" height="32" rx="3" ${S}/><path d="M5 16h38" ${S}/><circle cx="10" cy="12" r="1.3" ${F}/><circle cx="14.5" cy="12" r="1.3" ${F}/><path d="M12 24h24M12 30h16" ${S}/>` },
    nas: { label: 'NAS / Storage', svg: `<rect x="8" y="6" width="32" height="36" rx="3" ${S}/><path d="M15 6v36M24 6v36M33 6v36" ${S} stroke-width="1.6"/><circle cx="12" cy="37" r="1.5" ${F}/>` },
    camera: { label: 'Kamera', svg: `<path d="M6 14l26 4-2 10-26-4z" ${S}/><path d="M32 20l8-3v10l-8-1M14 26l-2 10h-6" ${S}/>` },
    phone: { label: 'Telefon', svg: `<rect x="13" y="4" width="22" height="40" rx="4" ${S}/><path d="M21 38h6" ${S}/>` },
    vm: { label: 'VM / Container', svg: `<path d="M24 5l17 9.5v19L24 43 7 33.5v-19z" ${S}/><path d="M7 14.5l17 9.5 17-9.5M24 24v19" ${S}/>` },
    iot: { label: 'IoT / Sensor', svg: `<rect x="14" y="14" width="20" height="20" rx="3" ${S}/><path d="M19 14V8M24 14V8M29 14V8M19 40v-6M24 40v-6M29 40v-6M14 19H8M14 24H8M14 29H8M40 19h-6M40 24h-6M40 29h-6" ${S}/>` },
    ups: { label: 'USV', svg: `<rect x="10" y="5" width="28" height="38" rx="3" ${S}/><path d="M26 12l-7 12h10l-7 12" ${S}/>` },
    loadbalancer: { label: 'Load Balancer', svg: `<circle cx="24" cy="24" r="17" ${S}/><path d="M12 24h8l8-8h8M28 32h8M20 24l8 8" ${S}/>` },
    user: { label: 'Benutzer', svg: `<circle cx="24" cy="16" r="8" ${S}/><path d="M8 42c1-9 7.5-14 16-14s15 5 16 14" ${S}/>` },
  };
  const html = (name, size = 24) => {
    const i = icons[name] || icons.server;
    return `<svg viewBox="0 0 48 48" width="${size}" height="${size}" aria-hidden="true">${i.svg}</svg>`;
  };
  return { icons, html };
})();

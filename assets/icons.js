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
    fritzbox: { label: 'FRITZ!Box / Router WLAN', svg: `<rect x="5" y="24" width="38" height="14" rx="4" ${S}/><path d="M12 24l-3-15M36 24l3-15" ${S}/><circle cx="12" cy="31" r="1.8" ${F}/><circle cx="18" cy="31" r="1.8" ${F}/><circle cx="24" cy="31" r="1.8" ${F}/><path d="M30 31h7" ${S}/>` },
    modem: { label: 'Modem / ONT', svg: `<rect x="6" y="18" width="36" height="20" rx="3" ${S}/><path d="M24 18V8M18 11a8 8 0 0 1 12 0" ${S}/><circle cx="13" cy="28" r="1.8" ${F}/><circle cx="19" cy="28" r="1.8" ${F}/><path d="M27 28h9" ${S}/>` },
    windows: { label: 'Windows-Server', svg: `<path d="M6 10l15-2v15H6zM24 7.5L42 5v18H24zM6 26h15v15L6 39zM24 26h18v18l-18-2.5z" ${S}/>` },
    linux: { label: 'Linux-Server', svg: `<rect x="5" y="7" width="38" height="34" rx="3" ${S}/><path d="M5 14h38M12 22l6 5-6 5M22 33h12" ${S}/>` },
    hypervisor: { label: 'Hypervisor / Proxmox', svg: `<rect x="5" y="30" width="38" height="11" rx="2" ${S}/><rect x="8" y="8" width="14" height="16" rx="2" ${S}/><rect x="26" y="8" width="14" height="16" rx="2" ${S}/><path d="M15 24v6M33 24v6" ${S}/><circle cx="11" cy="35.5" r="1.6" ${F}/>` },
    rack: { label: 'Rack / Schrank', svg: `<rect x="10" y="4" width="28" height="40" rx="2" ${S}/><path d="M14 11h20M14 18h20M14 25h20M14 32h20M14 39h20" ${S} stroke-width="1.8"/>` },
    storage: { label: 'Festplatte', svg: `<rect x="7" y="9" width="34" height="30" rx="4" ${S}/><circle cx="24" cy="22" r="8" ${S}/><circle cx="24" cy="22" r="2" ${F}/><path d="M33 34l-4-4" ${S}/>` },
    vpn: { label: 'VPN / Sicherheit', svg: `<path d="M24 4l16 6v12c0 10-7 17-16 21C15 39 8 32 8 22V10z" ${S}/><rect x="17" y="21" width="14" height="10" rx="2" ${S}/><path d="M20 21v-3a4 4 0 0 1 8 0v3" ${S}/>` },
    mail: { label: 'Mailserver', svg: `<rect x="5" y="10" width="38" height="28" rx="3" ${S}/><path d="M6 12l18 14 18-14" ${S}/>` },
    voip: { label: 'VoIP-Telefon', svg: `<rect x="8" y="16" width="32" height="24" rx="3" ${S}/><path d="M12 16c0-6 5-10 12-10s12 4 12 10" ${S}/><rect x="13" y="21" width="12" height="6" rx="1" ${S}/><path d="M30 22h4M30 27h4M14 33h3M21 33h3M28 33h3" ${S}/>` },
    tablet: { label: 'Tablet', svg: `<rect x="9" y="5" width="30" height="38" rx="4" ${S}/><circle cx="24" cy="38" r="1.6" ${F}/>` },
    tv: { label: 'TV / Display', svg: `<rect x="4" y="8" width="40" height="26" rx="2" ${S}/><path d="M16 42h16M20 34l-2 8M28 34l2 8" ${S}/>` },
    smarthome: { label: 'Smart Home', svg: `<path d="M6 22L24 7l18 15M11 18v22h26V18" ${S}/><path d="M19 29a7 7 0 0 1 10 0M16.5 25.5a11 11 0 0 1 15 0" ${S}/><circle cx="24" cy="33" r="1.8" ${F}/>` },
    antenna: { label: 'Funk / LTE / 5G', svg: `<path d="M24 20v24M18 44h12M24 20l-7 24M24 20l7 24" ${S}/><circle cx="24" cy="17" r="3" ${S}/><path d="M15 9a12 12 0 0 0 0 16M33 9a12 12 0 0 1 0 16M10 5a18 18 0 0 0 0 24M38 5a18 18 0 0 1 0 24" ${S}/>` },
    dome: { label: 'Dome-Kamera', svg: `<path d="M8 14h32" ${S}/><path d="M12 14v4a12 12 0 0 0 24 0v-4" ${S}/><circle cx="24" cy="21" r="4" ${S}/><path d="M10 9h28v5H10z" ${S}/>` },
    nvr: { label: 'NVR / Recorder', svg: `<rect x="4" y="16" width="40" height="16" rx="3" ${S}/><circle cx="11" cy="24" r="3" ${S}/><path d="M19 22h18M19 27h12" ${S}/>` },
    plug: { label: 'Steckdose / PDU', svg: `<rect x="8" y="8" width="32" height="32" rx="8" ${S}/><circle cx="24" cy="24" r="10" ${S}/><circle cx="20" cy="24" r="1.6" ${F}/><circle cx="28" cy="24" r="1.6" ${F}/>` },
    light: { label: 'Licht', svg: `<path d="M17 30a12 12 0 1 1 14 0v5H17z" ${S}/><path d="M19 40h10M21 44h6" ${S}/>` },
    thermo: { label: 'Klima / Temperatur', svg: `<path d="M20 28V9a4 4 0 0 1 8 0v19a8 8 0 1 1-8 0z" ${S}/><circle cx="24" cy="34" r="3" ${F}/><path d="M24 31V16M32 12h5M32 18h5M32 24h5" ${S}/>` },
    speaker: { label: 'Lautsprecher', svg: `<rect x="11" y="4" width="26" height="40" rx="4" ${S}/><circle cx="24" cy="29" r="8" ${S}/><circle cx="24" cy="13" r="3" ${S}/>` },
    solar: { label: 'PV / Wechselrichter', svg: `<path d="M8 30l6-18h20l6 18z" ${S}/><path d="M11 21h26M24 12v18M18 12l-2 18M30 12l2 18M24 30v10M16 42h16" ${S}/>` },
    car: { label: 'Wallbox / E-Auto', svg: `<path d="M7 30l4-11a4 4 0 0 1 4-3h18a4 4 0 0 1 4 3l4 11v8H7z" ${S}/><circle cx="15" cy="32" r="2.5" ${F}/><circle cx="33" cy="32" r="2.5" ${F}/><path d="M26 6l-4 6h5l-4 6" ${S}/>` },
    gamepad: { label: 'Konsole', svg: `<path d="M14 15h20a9 9 0 0 1 9 9l1 7a5 5 0 0 1-9 3l-3-4H16l-3 4a5 5 0 0 1-9-3l1-7a9 9 0 0 1 9-9z" ${S}/><path d="M14 21v6M11 24h6" ${S}/><circle cx="32" cy="22" r="1.6" ${F}/><circle cx="35" cy="26" r="1.6" ${F}/>` },
    scanner: { label: 'Scanner / Kopierer', svg: `<path d="M6 22h36v16H6z" ${S}/><path d="M6 22l6-10h28l2 10M12 30h24" ${S}/>` },
    office: { label: 'Standort / Gebäude', svg: `<rect x="8" y="6" width="22" height="38" ${S}/><path d="M30 18h10v26H30M13 12h4M21 12h4M13 19h4M21 19h4M13 26h4M21 26h4M17 44v-8h4v8" ${S}/>` },
  };
  // Eigene, hochgeladene Icons: { id, name, data } -> Schlüssel "c<ID>", als Bild gerendert
  const custom = {};
  const registerCustom = (list) => {
    for (const k of Object.keys(custom)) delete custom[k];
    for (const c of list || []) custom['c' + c.id] = { label: c.name, img: c.data, custom: true };
  };
  const get = (name) => custom[name] || icons[name] || icons.server;
  const all = () => Object.assign({}, icons, custom);
  /** SVG-Innenleben für eine 48×48-Box (Bild bei eigenen Icons). */
  const inner = (name, color) => {
    const i = get(name);
    if (i.img) return `<image href="${i.img}" x="0" y="0" width="48" height="48" preserveAspectRatio="xMidYMid meet"/>`;
    return color ? i.svg.replace(/currentColor/g, color) : i.svg;
  };
  const html = (name, size = 24) => `<svg viewBox="0 0 48 48" width="${size}" height="${size}" aria-hidden="true">${inner(name)}</svg>`;
  return { icons, custom, html, inner, get, all, registerCustom };
})();

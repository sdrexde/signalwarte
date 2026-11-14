#!/usr/bin/env bash
# Signalwarte Linux-Agent – meldet Systemzustand und Systeminformationen an Signalwarte.
#
#   sudo bash signalwarte-agent.sh            Installiert den Agenten (systemd-Dienst, sonst Cron)
#   sudo bash signalwarte-agent.sh install    dito
#   sudo bash signalwarte-agent.sh uninstall  Entfernt Dienst, Programm und Konfiguration
#   sudo bash signalwarte-agent.sh status     Zustand + letzte Logzeilen
#   sudo bash signalwarte-agent.sh test       Werte einmal sammeln, anzeigen und senden
#   signalwarte-agent.sh run                  Schleife (vom Dienst gestartet)
#   signalwarte-agent.sh once                 Einmal sammeln und senden (Cron-Betrieb)
#
# Voraussetzungen: bash, curl (oder wget), coreutils, procps. Beim Download von Signalwarte werden
# Server-Adresse und Token eingesetzt. Konfiguration nach der Installation: /etc/signalwarte-agent.conf
set -u

AGENT_VERSION="1.0.0"
INSTALL_DIR="/opt/signalwarte-agent"
INSTALLED="$INSTALL_DIR/signalwarte-agent.sh"
CONF="/etc/signalwarte-agent.conf"
STATE_DIR="/var/lib/signalwarte-agent"
LOG="/var/log/signalwarte-agent.log"
UNIT="/etc/systemd/system/signalwarte-agent.service"

# Beim Download von Signalwarte eingesetzt
EMBEDDED_URL='__SIGNALWARTE_URL__'

# ----------------------------------------------------------------------------- Hilfen
log() {
    local line
    line="$(date '+%Y-%m-%d %H:%M:%S') $*"
    [ -t 1 ] && echo "$line"
    if [ -w "$(dirname "$LOG")" ]; then
        echo "$line" >>"$LOG" 2>/dev/null
        [ -f "$LOG" ] && [ "$(stat -c %s "$LOG" 2>/dev/null || echo 0)" -gt 2097152 ] && mv -f "$LOG" "$LOG.1"
    fi
}

# JSON-String sicher escapen
js() {
    local s=${1-}
    s=${s//\\/\\\\}
    s=${s//\"/\\\"}
    s=${s//$'\n'/\\n}
    s=${s//$'\r'/}
    s=${s//$'\t'/ }
    printf '"%s"' "$s"
}

is_num() { [[ ${1-} =~ ^-?[0-9]+([.][0-9]+)?$ ]]; }

load_conf() {
    URL=""
    [ -f "$CONF" ] && . "$CONF"
    if [ -z "${URL:-}" ] && [[ $EMBEDDED_URL != __SIGNALWARTE* ]]; then URL="$EMBEDDED_URL"; fi
    if [ -z "${URL:-}" ]; then
        echo "Keine Konfiguration gefunden. Skript bitte über Signalwarte herunterladen (Sensor 'System-Agent' → Details)." >&2
        exit 1
    fi
}

http_post() {   # $1 = JSON
    if command -v curl >/dev/null 2>&1; then
        curl -fsS -m 30 -H 'Content-Type: application/json' --data-binary "$1" "$URL"
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O - -T 30 --header='Content-Type: application/json' --post-data="$1" "$URL"
    else
        echo "Weder curl noch wget gefunden" >&2
        return 1
    fi
}

# ----------------------------------------------------------------------------- Messwerte
cpu_sample() { awk '/^cpu /{idle=$5+$6; t=0; for(i=2;i<=NF;i++) t+=$i; print t, idle}' /proc/stat; }

net_sample() {  # Summe rx/tx aller "echten" Interfaces
    awk -F'[: ]+' 'NR>2 { gsub(/^ +/,""); n=$1; if (n=="lo" || n ~ /^(veth|docker|br-|virbr|tap|fwbr|fwpr|fwln|vnet|cali|flannel|cni)/) next; rx+=$2; tx+=$10 } END { printf "%d %d\n", rx, tx }' /proc/net/dev
}

collect() {
    local -a ch=()
    local c1 c2 n1 n2
    c1=$(cpu_sample); n1=$(net_sample)
    sleep 1
    c2=$(cpu_sample); n2=$(net_sample)
    local cpu
    cpu=$(awk -v a="$c1" -v b="$c2" 'BEGIN { split(a,x," "); split(b,y," "); dt=y[1]-x[1]; di=y[2]-x[2]; if (dt>0) printf "%.1f", 100*(1-di/dt); else print 0 }')
    ch+=("$(js 'CPU %'):$cpu")
    read -r l1 l5 l15 _ </proc/loadavg
    ch+=("$(js 'Load 1m'):$l1" "$(js 'Load 5m'):$l5" "$(js 'Load 15m'):$l15")
    ch+=("$(js 'CPU-Kerne'):$(nproc 2>/dev/null || echo 1)")

    local mt ma st sf
    mt=$(awk '/^MemTotal:/{print $2}' /proc/meminfo); ma=$(awk '/^MemAvailable:/{print $2}' /proc/meminfo)
    st=$(awk '/^SwapTotal:/{print $2}' /proc/meminfo); sf=$(awk '/^SwapFree:/{print $2}' /proc/meminfo)
    if [ "${mt:-0}" -gt 0 ]; then
        ch+=("$(js 'RAM %'):$(awk -v t="$mt" -v a="$ma" 'BEGIN{printf "%.1f", (t-a)/t*100}')")
        ch+=("$(js 'RAM frei GB'):$(awk -v a="$ma" 'BEGIN{printf "%.2f", a/1048576}')")
        ch+=("$(js 'RAM gesamt GB'):$(awk -v t="$mt" 'BEGIN{printf "%.1f", t/1048576}')")
    fi
    [ "${st:-0}" -gt 0 ] && ch+=("$(js 'Swap %'):$(awk -v t="$st" -v f="$sf" 'BEGIN{printf "%.1f", (t-f)/t*100}')")
    ch+=("$(js 'Laufzeit (Tage)'):$(awk '{printf "%.2f", $1/86400}' /proc/uptime)")
    ch+=("$(js 'Prozesse'):$(ls -d /proc/[0-9]* 2>/dev/null | wc -l)")
    ch+=("$(js 'Angemeldete Benutzer'):$(who 2>/dev/null | wc -l)")

    # Netzwerk-Rate über 1 s
    local nr
    nr=$(awk -v a="$n1" -v b="$n2" 'BEGIN { split(a,x," "); split(b,y," "); printf "%.3f %.3f", (y[1]-x[1])*8/1e6, (y[2]-x[2])*8/1e6 }')
    ch+=("$(js 'Netz Eingang Mbit/s'):${nr% *}" "$(js 'Netz Ausgang Mbit/s'):${nr#* }")

    # Laufwerke (max. 10 echte Dateisysteme)
    local disks_info=()
    while read -r fs size used avail pct mnt; do
        [ -z "$mnt" ] && continue
        local free
        free=$(awk -v s="$size" -v a="$avail" 'BEGIN{ if (s>0) printf "%.1f", a/s*100; else print 0 }')
        ch+=("$(js "Frei $mnt %"):$free" "$(js "Frei $mnt GB"):$(awk -v a="$avail" 'BEGIN{printf "%.1f", a/1048576}')")
        disks_info+=("{\"mount\":$(js "$mnt"),\"device\":$(js "$fs"),\"size_gb\":$(awk -v s="$size" 'BEGIN{printf "%.1f", s/1048576}'),\"free_gb\":$(awk -v a="$avail" 'BEGIN{printf "%.1f", a/1048576}')}")
    done < <(df -P -k -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs -x fuse.lxcfs -x nsfs 2>/dev/null | awk 'NR>1 && $2>1048576 && $6 !~ /^\/(etc|proc|sys|dev)\// {print $1, $2, $3, $4, $5, $6}' | head -10)

    # Temperatur (höchste Thermal-Zone)
    local temp=""
    for z in /sys/class/thermal/thermal_zone*/temp; do
        [ -r "$z" ] || continue
        local t; t=$(cat "$z" 2>/dev/null)
        is_num "$t" && { [ -z "$temp" ] || [ "$t" -gt "$temp" ]; } && temp=$t
    done
    [ -n "$temp" ] && ch+=("$(js 'CPU-Temperatur °C'):$(awk -v t="$temp" 'BEGIN{printf "%.1f", t/1000}')")

    # systemd: fehlgeschlagene Units + überwachte Dienste
    local failed_json="[]" services_json="{}"
    if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
        local failed
        failed=$(systemctl --failed --no-legend --plain 2>/dev/null | awk '{print $1}' | grep -v '^$' | head -20)
        ch+=("$(js 'Dienste fehlgeschlagen'):$(printf '%s' "$failed" | grep -c . || true)")
        if [ -n "$failed" ]; then
            failed_json="[$(printf '%s\n' "$failed" | while read -r u; do printf '%s,' "$(js "$u")"; done | sed 's/,$//')]"
        fi
        if [ -n "${WATCH:-}" ]; then
            local parts=() w
            for w in ${WATCH//,/ }; do parts+=("$(js "$w"):$(js "$(systemctl is-active "$w" 2>/dev/null || true)")"); done
            services_json="{$(IFS=,; echo "${parts[*]}")}"
        fi
    fi

    # Neustart nötig
    local rb=0
    [ -f /var/run/reboot-required ] && rb=1
    if command -v needs-restarting >/dev/null 2>&1; then needs-restarting -r >/dev/null 2>&1 || rb=1; fi
    ch+=("$(js 'Neustart ausstehend'):$rb")

    # Updates (höchstens stündlich, ohne Paketlisten zu aktualisieren)
    local uf="$STATE_DIR/updates" now; now=$(date +%s)
    if [ ! -f "$uf" ] || [ $((now - $(stat -c %Y "$uf" 2>/dev/null || echo 0))) -gt 3600 ]; then
        local up=-1 sec=-1
        if command -v apt-get >/dev/null 2>&1; then
            local sim; sim=$(LANG=C apt-get -s -o Debug::NoLocking=true upgrade 2>/dev/null | grep '^Inst ')
            up=$(printf '%s' "$sim" | grep -c . || true); sec=$(printf '%s' "$sim" | grep -ci security || true)
        elif command -v dnf >/dev/null 2>&1; then
            up=$(dnf -q --cacheonly check-update 2>/dev/null | grep -cE '^[A-Za-z0-9]' || true)
            sec=$(dnf -q --cacheonly updateinfo list --security 2>/dev/null | grep -c . || true)
        elif command -v apk >/dev/null 2>&1; then
            up=$(apk version -l '<' 2>/dev/null | tail -n +2 | grep -c . || true)
        fi
        mkdir -p "$STATE_DIR" 2>/dev/null && echo "$up $sec" >"$uf"
    fi
    if [ -f "$uf" ]; then
        read -r up sec <"$uf"
        [ "${up:--1}" -ge 0 ] && ch+=("$(js 'Updates ausstehend'):$up")
        [ "${sec:--1}" -ge 0 ] && ch+=("$(js 'Sicherheitsupdates'):$sec")
    fi

    # ------------------------------------------------------------- Systeminformationen
    local osn kern arch cpum virt vendor model serial bios ips macs gw dns boot tz fqdn
    osn=$( (. /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-Linux}") )
    kern=$(uname -r); arch=$(uname -m)
    cpum=$(awk -F': ' '/model name|Hardware|Processor/{print $2; exit}' /proc/cpuinfo)
    virt=$(systemd-detect-virt 2>/dev/null || echo unbekannt)
    vendor=$(cat /sys/class/dmi/id/sys_vendor 2>/dev/null); model=$(cat /sys/class/dmi/id/product_name 2>/dev/null)
    serial=$(cat /sys/class/dmi/id/product_serial 2>/dev/null); bios=$(cat /sys/class/dmi/id/bios_version 2>/dev/null)
    [ -z "$model" ] && model=$(tr -d '\0' </proc/device-tree/model 2>/dev/null)
    local skip='^(lo|docker|br-|veth|virbr|tap|fwbr|fwpr|fwln|vnet|cali|flannel|cni)'
    ips=$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $2"="$4}' | grep -Ev "$skip" | head -8 | paste -sd ' ')
    macs=$(ip -o link 2>/dev/null | awk '{n=$2; sub(/@.*/,"",n); sub(/:$/,"",n); for (i=1;i<NF;i++) if ($i=="link/ether") print n"="$(i+1)}' | grep -Ev "$skip" | head -8 | paste -sd ' ')
    gw=$(ip -4 route show default 2>/dev/null | awk '{print $3; exit}')
    dns=$(awk '/^nameserver/{print $2}' /etc/resolv.conf 2>/dev/null | paste -sd ' ')
    boot=$(date -d "@$(( $(date +%s) - $(cut -d. -f1 /proc/uptime) ))" '+%Y-%m-%d %H:%M' 2>/dev/null)
    tz=$(cat /etc/timezone 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null)
    fqdn=$(hostname -f 2>/dev/null || hostname)

    local info="{\"platform\":\"linux\",\"agent\":$(js "$AGENT_VERSION"),\"host\":$(js "$(hostname)"),\"fqdn\":$(js "$fqdn"),\"os\":$(js "$osn"),\"kernel\":$(js "$kern"),\"arch\":$(js "$arch"),\"cpu\":$(js "$cpum"),\"cores\":$(nproc 2>/dev/null || echo 1),\"ram_gb\":$(awk -v t="${mt:-0}" 'BEGIN{printf "%.1f", t/1048576}'),\"virtualization\":$(js "$virt"),\"manufacturer\":$(js "$vendor"),\"model\":$(js "$model"),\"serial\":$(js "$serial"),\"bios\":$(js "$bios"),\"ip\":$(js "$ips"),\"mac\":$(js "$macs"),\"gateway\":$(js "$gw"),\"dns\":$(js "$dns"),\"last_boot\":$(js "$boot"),\"timezone\":$(js "$tz"),\"disks\":[$(IFS=,; echo "${disks_info[*]:-}")]}"

    printf '{"channels":{%s},"services":%s,"failed":%s,"info":%s}' "$(IFS=,; echo "${ch[*]}")" "$services_json" "$failed_json" "$info"
}

send_once() {
    load_conf
    mkdir -p "$STATE_DIR" 2>/dev/null
    WATCH=$(cat "$STATE_DIR/services" 2>/dev/null || true)
    local body resp
    body=$(collect)
    if resp=$(http_post "$body" 2>&1); then
        # Antwort: überwachte Dienste + Intervall übernehmen
        local svc iv st
        svc=$(printf '%s' "$resp" | grep -o '"services":\[[^]]*\]' | sed 's/.*\[//; s/\]//; s/"//g')
        iv=$(printf '%s' "$resp" | grep -o '"interval":[0-9]*' | cut -d: -f2)
        st=$(printf '%s' "$resp" | grep -o '"status":"[a-z]*"' | cut -d'"' -f4)
        printf '%s' "$svc" >"$STATE_DIR/services.new" 2>/dev/null && mv -f "$STATE_DIR/services.new" "$STATE_DIR/services"
        [ -n "$iv" ] && echo "$iv" >"$STATE_DIR/interval"
        date '+%Y-%m-%d %H:%M:%S' >"$STATE_DIR/last_ok"; echo "${st:-?}" >"$STATE_DIR/last_status"
        RESP_STATUS=$st
        # neue Dienstliste sofort einmal nachliefern
        if [ "$svc" != "${WATCH:-}" ] && [ -n "$svc" ] && [ "${1:-}" != "noretry" ]; then send_once noretry; fi
        return 0
    else
        log "WARN Senden fehlgeschlagen: $resp"
        return 1
    fi
}

run_loop() {
    load_conf
    log "INFO Agent $AGENT_VERSION gestartet, Server ${URL%%\?*}"
    while true; do
        send_once || true
        local iv; iv=$(cat "$STATE_DIR/interval" 2>/dev/null || echo 120)
        is_num "$iv" || iv=120
        [ "$iv" -lt 30 ] && iv=30
        sleep "$iv"
    done
}

# ----------------------------------------------------------------------------- Installation
need_root() { [ "$(id -u)" -eq 0 ] || { echo "Bitte mit sudo/als root ausführen." >&2; exit 1; }; }

do_install() {
    need_root
    local url="${URL:-}"
    [ -f "$CONF" ] && . "$CONF"
    if [[ $EMBEDDED_URL != __SIGNALWARTE* ]]; then url="$EMBEDDED_URL"; else url="${URL:-}"; fi
    [ -z "$url" ] && { echo "Dieses Skript enthält keine Server-Adresse. Bitte über Signalwarte herunterladen." >&2; exit 1; }
    command -v curl >/dev/null 2>&1 || command -v wget >/dev/null 2>&1 || {
        echo "curl wird installiert …"; (apt-get install -y curl || dnf install -y curl || apk add curl) >/dev/null 2>&1; }
    echo "Signalwarte-Agent $AGENT_VERSION wird installiert …"
    mkdir -p "$INSTALL_DIR" "$STATE_DIR"
    chmod 700 "$STATE_DIR"
    local self; self=$(readlink -f "$0")
    [ "$self" != "$INSTALLED" ] && cp -f "$self" "$INSTALLED"
    chmod 700 "$INSTALLED"
    umask 077
    printf 'URL=%q\n' "$url" >"$CONF"
    chmod 600 "$CONF"
    URL="$url"
    if send_once; then echo "Verbindung zu Signalwarte erfolgreich (Status: ${RESP_STATUS:-?})."; else echo "WARNUNG: Signalwarte nicht erreichbar – der Agent versucht es weiter."; fi
    if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
        cat >"$UNIT" <<EOF
[Unit]
Description=Signalwarte Agent (meldet Systemzustand an Signalwarte)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=$INSTALLED run
Restart=always
RestartSec=30
Nice=10

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload
        systemctl enable --now signalwarte-agent.service >/dev/null 2>&1
        systemctl restart signalwarte-agent.service
        echo "Installiert: systemd-Dienst 'signalwarte-agent' läuft."
    else
        # ohne systemd: Cron (bei Bedarf nachinstallieren)
        if [ ! -d /etc/cron.d ]; then
            echo "Kein systemd und kein Cron gefunden – Cron wird installiert …"
            (apt-get install -y -qq cron || dnf install -y -q cronie || yum install -y -q cronie) >/dev/null 2>&1 || true
        fi
        if [ ! -d /etc/cron.d ]; then
            echo "FEHLER: Weder systemd noch Cron verfügbar. Bitte 'cron' installieren und erneut ausführen." >&2
            exit 1
        fi
        echo "*/2 * * * * root $INSTALLED once >/dev/null 2>&1" >/etc/cron.d/signalwarte-agent
        chmod 644 /etc/cron.d/signalwarte-agent
        (service cron start || service crond start || /usr/sbin/cron) >/dev/null 2>&1 || true
        echo "Installiert: Cron-Job /etc/cron.d/signalwarte-agent (alle 2 Minuten)."
    fi
    echo "Konfiguration: $CONF   Log: $LOG   Status: sudo $INSTALLED status"
}

do_uninstall() {
    need_root
    if [ -f "$UNIT" ]; then systemctl disable --now signalwarte-agent.service >/dev/null 2>&1; rm -f "$UNIT"; systemctl daemon-reload; fi
    rm -f /etc/cron.d/signalwarte-agent "$CONF"
    rm -rf "$INSTALL_DIR" "$STATE_DIR"
    echo "Signalwarte-Agent entfernt."
}

do_status() {
    if [ -f "$UNIT" ]; then systemctl --no-pager status signalwarte-agent.service 2>/dev/null | head -5
    elif [ -f /etc/cron.d/signalwarte-agent ]; then echo "Betrieb per Cron: $(cat /etc/cron.d/signalwarte-agent)"
    else echo "Nicht installiert."; fi
    [ -f "$STATE_DIR/last_ok" ] && echo "Letzte erfolgreiche Meldung: $(cat "$STATE_DIR/last_ok")  Status: $(cat "$STATE_DIR/last_status" 2>/dev/null)"
    [ -s "$STATE_DIR/services" ] && echo "Überwachte Dienste: $(cat "$STATE_DIR/services")"
    [ -f "$LOG" ] && { echo "--- $LOG ---"; tail -n 10 "$LOG"; }
}

do_test() {
    load_conf
    mkdir -p "$STATE_DIR" 2>/dev/null
    WATCH=$(cat "$STATE_DIR/services" 2>/dev/null || true)
    local body; body=$(collect)
    if command -v python3 >/dev/null 2>&1; then printf '%s' "$body" | python3 -m json.tool; else printf '%s\n' "$body"; fi
    # wie im Dienst senden (übernimmt Dienstliste/Intervall vom Server und liefert ggf. sofort nach)
    if send_once; then
        echo "Gesendet. Status bei Signalwarte: ${RESP_STATUS:-?}  Überwachte Dienste: $(cat "$STATE_DIR/services" 2>/dev/null)"
    else
        echo "FEHLER beim Senden – siehe $LOG"; exit 1
    fi
}

case "${1:-install}" in
    install) do_install ;;
    uninstall) do_uninstall ;;
    status) do_status ;;
    test) do_test ;;
    run) run_loop ;;
    once) send_once ;;
    collect) WATCH="${2:-}"; collect ;;   # nur sammeln (Diagnose), nichts senden
    *) sed -n '2,13p' "$0"; exit 1 ;;
esac

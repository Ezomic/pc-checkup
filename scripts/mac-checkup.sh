#!/bin/bash
# Read-only Mac checkup. Writes a Markdown report to the Desktop.
# Usage: bash mac-checkup.sh [--token TOKEN] [--url URL]
#
# Optional upload: with a token the finished report is also sent to PC Checkup. The token is
# read from, in this order: --token, the PC_CHECKUP_TOKEN environment variable, or a file
# named pc-checkup.token next to this script. The site defaults to PC_DEFAULT_URL below;
# --url or PC_CHECKUP_URL override it (https only, plus http://localhost for testing).
# The token is never written to the report or printed. Bash 3.2 (stock macOS) compatible.

set -u

PCC_DEFAULT_URL="https://pc-checkup.thijssensoftware.nl"
PCC_SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)
PCC_TOKEN=""
PCC_TOKEN_SOURCE=""

# ---------- Upload helpers ----------
pcc_clean() { printf '%s' "$1" | tr -d '\r' | head -n 1 | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'; }

pcc_oneline() { printf '%s' "$1" | tr '\r\n\t' '   ' | tr -d '\000-\037\177'; }

# Sets PCC_TOKEN and PCC_TOKEN_SOURCE (option, environment or file); both stay empty without one.
pcc_find_token() {
  local value
  PCC_TOKEN=""
  PCC_TOKEN_SOURCE=""
  value=$(pcc_clean "${1:-}")
  if [ -n "$value" ]; then PCC_TOKEN=$value; PCC_TOKEN_SOURCE=option; return 0; fi
  value=$(pcc_clean "${PC_CHECKUP_TOKEN:-}")
  if [ -n "$value" ]; then PCC_TOKEN=$value; PCC_TOKEN_SOURCE=environment; return 0; fi
  if [ -n "${BASH_SOURCE[0]:-}" ] && [ -r "$PCC_SCRIPT_DIR/pc-checkup.token" ]; then
    value=$(pcc_clean "$(head -n 1 "$PCC_SCRIPT_DIR/pc-checkup.token")")
    if [ -n "$value" ]; then PCC_TOKEN=$value; PCC_TOKEN_SOURCE="file"; return 0; fi
  fi
  return 0
}

pcc_valid_token() { printf '%s' "$1" | grep -Eq '^[A-Za-z0-9|_.~+/=-]{1,255}$'; }

pcc_valid_url() {
  printf '%s' "$1" | grep -Eiq '^(https://[A-Za-z0-9.-]+|http://(localhost|127\.0\.0\.1))(:[0-9]+)?(/[^[:space:]\\?#]*)?$'
}

# Prints {"markdown":"<file>"} with quotes, backslashes, control characters and non-ASCII
# text escaped. Perl is always on a Mac; invalid UTF-8 becomes U+FFFD.
pcc_json_body() {
  /usr/bin/perl -MEncode -0777 -e '
    my $s = decode("UTF-8", scalar(<STDIN>) // "");
    $s =~ s/([\\"])/\\$1/g;
    $s =~ s/\n/\\n/g;
    $s =~ s/\r/\\r/g;
    $s =~ s/\t/\\t/g;
    $s =~ s/([\x00-\x1f])/sprintf("\\u%04x", ord($1))/ge;
    print encode("UTF-8", "{\"markdown\":\"" . $s . "\"}");
  ' < "$1"
}

# Prints machine_id=, message= and error= lines for the JSON file given.
pcc_response_fields() {
  /usr/bin/perl -MJSON::PP -0777 -e '
    my $d = eval { decode_json(scalar(<STDIN>) // "") };
    exit 0 unless ref $d eq "HASH";
    binmode(STDOUT, ":utf8");
    for my $k (qw(machine_id message)) { print "$k=$d->{$k}\n" if defined $d->{$k} && !ref $d->{$k}; }
    if (ref $d->{errors} eq "HASH") {
      for my $k (sort keys %{$d->{errors}}) {
        my $v = $d->{errors}{$k};
        print "error=$_\n" for grep { !ref $_ } (ref $v eq "ARRAY" ? @$v : ($v));
      }
    }
  ' < "$1"
}

# Sends the report file ($1) to the site ($2) with the token ($3) and prints one line saying
# what happened. The token only travels on curl's stdin config, never in its arguments.
pcc_upload() {
  local file=$1 base=$2 token=$3 json resp err status rc line machine="" message="" errors=""
  json=$(mktemp)
  resp=$(mktemp)
  err=$(mktemp)
  pcc_json_body "$file" > "$json"
  status=$(printf 'header = "Authorization: Bearer %s"\n' "$token" | curl -sS -K - --max-time 30 -X POST \
    -H 'Content-Type: application/json; charset=utf-8' -H 'Accept: application/json' \
    --data-binary @"$json" -o "$resp" -w '%{http_code}' "$base/api/reports" 2>"$err")
  rc=$?

  if [ "$rc" -ne 0 ]; then
    echo "Upload failed: could not reach $base (curl exit $rc: $(pcc_oneline "$(head -n 1 "$err")"))."
  else
    while IFS= read -r line; do
      case $line in
        machine_id=*) machine=$(pcc_oneline "${line#machine_id=}") ;;
        message=*) message=$(pcc_oneline "${line#message=}") ;;
        error=*) errors="${errors:+$errors; }$(pcc_oneline "${line#error=}")" ;;
      esac
    done <<< "$(pcc_response_fields "$resp")"
    case $machine in '' | *[!0-9]*) machine="" ;; esac
    [ "$errors" = "$message" ] && errors=""
    case $status in
      201) echo "Uploaded as a new report: machine $machine, $base/machines/$machine" ;;
      200) echo "Already uploaded: this identical report is already in PC Checkup (machine $machine, $base/machines/$machine)." ;;
      401) echo "Upload failed: the token was not accepted (invalid, expired or revoked). Create a new one in the app under Settings, API tokens." ;;
      403) echo "Upload failed: the token does not have the Write ability. Create a new token with the Write ability." ;;
      422) echo "The server rejected the report: ${message:-no reason given}${errors:+ ($errors)}" ;;
      429) echo "Upload skipped: the server is rate limiting this token, try again in a minute." ;;
      *) echo "Upload failed: the server answered HTTP $status." ;;
    esac
  fi
  rm -f "$json" "$resp" "$err"
}

# Called once the report is saved: works out the token and site, uploads, or explains how to.
pcc_upload_report() {
  local file=$1 base
  if [ -z "$PCC_TOKEN" ]; then
    echo "To upload this report: create a token with only the Write ability in the app (Settings, API tokens), then run again with PC_CHECKUP_TOKEN set or a pc-checkup.token file next to this script."
    return 0
  fi
  base=$(pcc_clean "${PCC_CLI_URL:-${PC_CHECKUP_URL:-$PCC_DEFAULT_URL}}")
  base=${base%/}
  if ! pcc_valid_url "$base"; then
    echo "Upload skipped: the site address must start with https:// (http://localhost is allowed for testing)."
  elif ! pcc_valid_token "$PCC_TOKEN"; then
    echo "Upload skipped: the token contains characters a token never has. Check the $PCC_TOKEN_SOURCE it came from."
  else
    pcc_upload "$file" "$base" "$PCC_TOKEN"
  fi
  echo "Token safety: use a token with only the Write ability and a short lifetime, and delete the token from any machine that is not yours."
}

# Sourcing this file (for tests) stops here instead of running the checkup.
if [ -n "${BASH_SOURCE[0]:-}" ] && [ "${BASH_SOURCE[0]}" != "$0" ]; then return 0; fi

PCC_CLI_TOKEN=""
PCC_CLI_URL=""
while [ $# -gt 0 ]; do
  case $1 in
    --token) [ $# -ge 2 ] || { echo "--token needs a value."; exit 2; }; PCC_CLI_TOKEN=$2; shift 2 ;;
    --token=*) PCC_CLI_TOKEN=${1#--token=}; shift ;;
    --url) [ $# -ge 2 ] || { echo "--url needs a value."; exit 2; }; PCC_CLI_URL=$2; shift 2 ;;
    --url=*) PCC_CLI_URL=${1#--url=}; shift ;;
    *) echo "Unknown option: $1. Usage: bash mac-checkup.sh [--token TOKEN] [--url URL]"; exit 2 ;;
  esac
done
pcc_find_token "$PCC_CLI_TOKEN"
PCC_CLI_TOKEN=""

STAMP=$(date +%Y%m%d-%H%M%S)
HOST=$(scutil --get ComputerName 2>/dev/null || hostname)
OUT="$HOME/Desktop/mac-checkup-${STAMP}.md"
BODY=$(mktemp)
FLAGS=$(mktemp)

flag() { echo "- $1" >> "$FLAGS"; }

section() {
  local title="$1"; shift
  {
    echo
    echo "## $title"
    echo
    echo '```'
    "$@" 2>&1 | sed 's/\r$//'
    echo '```'
  } >> "$BODY"
}

sh_section() { section "$1" bash -c "$2"; }

echo "Running checkup, this can take a minute or two..."

# ---------- Flags ----------
SP_HW=$(system_profiler SPHardwareDataType 2>/dev/null)
SP_PWR=$(system_profiler SPPowerDataType 2>/dev/null)

CYCLES=$(echo "$SP_PWR" | awk -F': ' '/Cycle Count/ {print $2; exit}')
COND=$(echo "$SP_PWR" | awk -F': ' '/Condition/ {print $2; exit}')
MAXCAP=$(echo "$SP_PWR" | awk -F': ' '/Maximum Capacity/ {print $2; exit}')
if [ -n "${CYCLES:-}" ]; then
  [ "$CYCLES" -ge 800 ] 2>/dev/null && flag "Battery has $CYCLES cycles (high)."
fi
if [ -n "${COND:-}" ] && [ "$COND" != "Normal" ]; then flag "Battery condition is '$COND'."; fi
if [ -n "${MAXCAP:-}" ]; then
  PCT=${MAXCAP%\%}
  [ "$PCT" -lt 80 ] 2>/dev/null && flag "Battery maximum capacity is $MAXCAP (below 80%)."
fi

fdesetup status 2>/dev/null | grep -q "Off" && flag "FileVault is OFF."
csrutil status 2>/dev/null | grep -q "disabled" && flag "System Integrity Protection is DISABLED."
spctl --status 2>/dev/null | grep -q "disabled" && flag "Gatekeeper is DISABLED."
/usr/libexec/ApplicationFirewall/socketfilterfw --getglobalstate 2>/dev/null | grep -qi "disabled" && flag "Application firewall is off."

ENROLL=$(profiles status -type enrollment 2>/dev/null)
echo "$ENROLL" | grep -qi "MDM enrollment: Yes" && flag "Enrolled in MDM (managed by an organisation)."
echo "$ENROLL" | grep -qi "Enrolled via DEP: Yes" && flag "Enrolled via Apple Business/School Manager (DEP/ADE)."

echo "$SP_HW" | grep -i "Activation Lock Status" | grep -qi "Enabled" && flag "Activation Lock is ENABLED. Needs the previous owner's Apple ID to be removed."

DISKSMART=$(diskutil info / 2>/dev/null | awk -F': +' '/SMART Status/ {print $2; exit}')
if [ -n "${DISKSMART:-}" ] && [ "$DISKSMART" != "Verified" ] && [ "$DISKSMART" != "Not Supported" ]; then
  flag "Disk SMART status is '$DISKSMART'."
fi

FREE_PCT=$(df -k /System/Volumes/Data 2>/dev/null | awk 'NR==2 {printf "%d", ($4/$2)*100}')
[ -n "${FREE_PCT:-}" ] && [ "$FREE_PCT" -lt 15 ] 2>/dev/null && flag "Only about ${FREE_PCT}% of disk is free."

PANICS=$(ls /Library/Logs/DiagnosticReports/*.panic 2>/dev/null | wc -l | tr -d ' ')
[ "${PANICS:-0}" -gt 0 ] && flag "$PANICS kernel panic report(s) found in /Library/Logs/DiagnosticReports."

UPDATES=$(softwareupdate -l 2>&1)
[ "$(profiles list 2>/dev/null | grep -c 'profileIdentifier')" -gt 0 ] && flag "Configuration profiles are installed (see MDM and enrolment section)."
[ "$(security dump-trust-settings -d 2>&1 | grep -c 'Cert [0-9]*:')" -gt 0 ] && flag "Custom admin-trusted certificates are installed (see Trusted certificates section)."

echo "$UPDATES" | grep -q "^\* Label" && flag "Software updates are pending (see Software updates section)."

OSMAJOR=$(sw_vers -productVersion | cut -d. -f1)
[ "${OSMAJOR:-0}" -lt 13 ] 2>/dev/null && flag "macOS $(sw_vers -productVersion) is old and likely out of security support."

NCPU=$(sysctl -n hw.ncpu)
LOAD1=$(sysctl -n vm.loadavg | awk '{print $2}')
awk -v l="$LOAD1" -v n="$NCPU" 'BEGIN {exit !(l > n)}' && flag "1 minute load average $LOAD1 exceeds the $NCPU CPU cores."

SWAP_MB=$(sysctl -n vm.swapusage | awk '{gsub("M","",$6); printf "%d", $6}')
[ "${SWAP_MB:-0}" -gt 2048 ] 2>/dev/null && flag "Swap in use is ${SWAP_MB} MB, so the Mac is short on RAM."

MEM_FREE=$(memory_pressure 2>/dev/null | awk -F': ' '/free percentage/ {gsub("%","",$2); print $2}')
[ -n "${MEM_FREE:-}" ] && [ "$MEM_FREE" -lt 15 ] 2>/dev/null && flag "Only ${MEM_FREE}% of memory is free (high memory pressure)."

pmset -g 2>/dev/null | grep -i lowpowermode | grep -q 1 && flag "Low Power Mode is on, which throttles performance."

THERM=$(pmset -g therm 2>/dev/null | awk -F'= ' '/CPU_Speed_Limit/ {print $2}')
[ -n "${THERM:-}" ] && [ "$THERM" -lt 100 ] 2>/dev/null && flag "CPU is thermally throttled to ${THERM}% (check vents, dust, heavy load)."

BOOT=$(sysctl -n kern.boottime | sed 's/.*sec = \([0-9]*\).*/\1/')
UPDAYS=$(( ( $(date +%s) - BOOT ) / 86400 ))
[ "$UPDAYS" -ge 30 ] && flag "Not restarted for $UPDAYS days."

SECURITY_TOOLS=$(ps -axco comm | grep -iE 'falcon|sophos|norton|mcafee|avast|avg|malwarebytes|jamf|kandji|crowdstrike|sentinel|defender|carbonblack|eset' | sort -u | tr '\n' ' ')
[ -n "${SECURITY_TOOLS:-}" ] && flag "Security or management agents running (can slow a Mac): $SECURITY_TOOLS"

ps -axco comm,pcpu | awk '$1 ~ /^(mds_stores|mds|mdworker_shared|photoanalysisd|backupd|softwareupdated)$/ && $2 > 20 {print $1}' | sort -u | tr '\n' ' ' | grep -q . && flag "Background indexing/backup process is using heavy CPU (Spotlight, Photos or Time Machine)."

# ---------- Sections ----------
section "Hardware overview" system_profiler SPHardwareDataType
sh_section "macOS version" "sw_vers; uname -a; uptime"
sh_section "Model identifier and chip" "sysctl -n hw.model; sysctl -n machdep.cpu.brand_string 2>/dev/null; sysctl -n hw.ncpu; sysctl -n hw.memsize | awk '{printf \"%.0f GB RAM\\n\", \$1/1073741824}'"
section "Battery and power" system_profiler SPPowerDataType
sh_section "Battery raw (ioreg)" "ioreg -rn AppleSmartBattery | grep -E 'CycleCount|DesignCapacity|MaxCapacity|AppleRawMaxCapacity|NominalChargeCapacity|Temperature|Serial|Manufacturer' "
sh_section "Charger" "pmset -g adapter 2>/dev/null; pmset -g batt"
section "Storage (volumes)" diskutil list
sh_section "Storage (boot volume)" "diskutil info / | grep -E 'Device Node|Volume Name|Container Total Space|Container Free Space|Solid State|SMART Status|File System|Protocol|Device / Media Name'"
section "Disk usage" df -h
section "Memory pressure" memory_pressure
sh_section "Top processes by CPU" "ps -Arco pid,pcpu,pmem,comm | head -12"
sh_section "Top processes by memory" "ps -Amrco pid,pcpu,pmem,comm | head -12"
sh_section "Performance: load, swap, memory, throttling" "sysctl vm.loadavg vm.swapusage; pmset -g therm; pmset -g | grep -i lowpowermode; echo; vm_stat | head -12"
sh_section "Performance: 10 second CPU sample (second snapshot is the real one)" "top -l 2 -s 5 -n 12 -o cpu -stats pid,command,cpu,mem,state | awk '/^Processes:/ {n++} n==2'"
sh_section "Performance: Spotlight indexing" "mdutil -s / 2>&1; ps -axco pid,pcpu,comm | grep -E 'mds|mdworker' | head -5"
sh_section "Performance: disk write and read speed (512 MB test)" "T=\$(mktemp \"\$HOME/.speedtest.XXXXXX\"); dd if=/dev/zero of=\"\$T\" bs=1m count=512 2>&1 | tail -1; sync; purge 2>/dev/null; dd if=\"\$T\" of=/dev/null bs=1m 2>&1 | tail -1; rm -f \"\$T\"; echo; echo 'Healthy SSD: several hundred MB/s or more. Under ~100 MB/s means a failing or full disk.'"
sh_section "Performance: security and management agents" "ps -axco comm | grep -iE 'falcon|sophos|norton|mcafee|avast|avg|malwarebytes|jamf|kandji|crowdstrike|sentinel|defender|carbonblack|eset' | sort -u; echo; echo '(blank above means none found)'"
sh_section "Performance: login item and startup counts" "echo LaunchAgents/Daemons:; ls /Library/LaunchAgents /Library/LaunchDaemons \"\$HOME/Library/LaunchAgents\" 2>/dev/null | grep -v '^\$' | grep -vc '^/'"
sh_section "Performance: biggest folders in home" "du -sh \"\$HOME\"/* \"\$HOME\"/Library/Caches 2>/dev/null | sort -rh | head -12"
section "Graphics and displays" system_profiler SPDisplaysDataType
section "Network interfaces" networksetup -listallhardwareports
sh_section "Wi-Fi" "system_profiler SPAirPortDataType | head -40"
section "Bluetooth" system_profiler SPBluetoothDataType
section "USB devices" system_profiler SPUSBDataType
section "Thunderbolt" system_profiler SPThunderboltDataType
section "Cameras and audio" system_profiler SPCameraDataType SPAudioDataType
section "Printers" system_profiler SPPrintersDataType

sh_section "Security: FileVault, SIP, Gatekeeper, firewall" "fdesetup status; csrutil status; spctl --status; /usr/libexec/ApplicationFirewall/socketfilterfw --getglobalstate; /usr/libexec/ApplicationFirewall/socketfilterfw --getstealthmode"
sh_section "Security: secure boot and firmware" "system_profiler SPiBridgeDataType 2>/dev/null | head -30; nvram -p 2>/dev/null | grep -iE 'boot-args|efi-boot|fmm|prev-lang' "
sh_section "Activation Lock and Find My" "system_profiler SPHardwareDataType | grep -i 'activation lock'; nvram -p 2>/dev/null | grep -i fmm"
sh_section "MDM and enrolment" "profiles status -type enrollment; echo; profiles list 2>&1 | head -40"
sh_section "Firmware password (Intel only)" "firmwarepasswd -check 2>&1 | head -5"
sh_section "Sharing and remote access" "systemsetup -getremotelogin 2>&1; systemsetup -getremoteappleevents 2>&1; launchctl print-disabled system 2>/dev/null | grep -iE 'ssh|screensharing|remotemanagement'"

sh_section "User accounts" "dscl . list /Users | grep -v '^_'; echo; echo 'Admin group members:'; dscl . -read /Groups/admin GroupMembership 2>/dev/null; echo; echo 'Console user:'; stat -f%Su /dev/console"
sh_section "Logged in users and recent logins" "who; echo; last -10"

section "Software updates" echo "$UPDATES"
sh_section "Installed apps (/Applications)" "ls -1 /Applications; echo; echo '--- user apps ---'; ls -1 \"\$HOME/Applications\" 2>/dev/null"
sh_section "Installed apps with versions" "system_profiler SPApplicationsDataType -detailLevel mini | grep -E '^    [^ ]|Version:|Obtained from:' | head -300"
sh_section "Homebrew" "command -v brew >/dev/null && brew list --versions 2>/dev/null | head -100 || echo 'Homebrew not installed'"
sh_section "Configuration profiles and kernel/system extensions" "systemextensionsctl list 2>&1 | head -40; echo; kextstat 2>/dev/null | grep -v com.apple | head -40"
sh_section "Startup items: LaunchAgents and LaunchDaemons" "ls -1 /Library/LaunchAgents /Library/LaunchDaemons \"\$HOME/Library/LaunchAgents\" 2>/dev/null"
sh_section "Login items" "osascript -e 'tell application \"System Events\" to get the name of every login item' 2>&1"
sh_section "Background items (macOS 13+)" "sfltool dumpbtm 2>&1 | grep -E 'Name:|Developer Name:|Type:|Disposition:' | head -150"
sh_section "Cron jobs" "crontab -l 2>&1"

sh_section "Listening network ports" "lsof -nP -iTCP -sTCP:LISTEN 2>/dev/null | head -40"
sh_section "DNS and proxy" "scutil --dns | head -25; scutil --proxy"
sh_section "Hosts file (non-default lines)" "grep -vE '^\s*#|^\s*$' /etc/hosts"

sh_section "Kernel panic reports" "ls -lt /Library/Logs/DiagnosticReports/*.panic 2>&1 | head -10; ls -lt /Library/Logs/DiagnosticReports 2>/dev/null | head -15"
sh_section "Recent shutdown causes" "log show --predicate 'eventMessage contains \"Previous shutdown cause\"' --last 30d 2>/dev/null | tail -8"
sh_section "Time Machine" "tmutil destinationinfo 2>&1 | head -15; tmutil latestbackup 2>&1"
sh_section "Privacy permissions (TCC), needs Full Disk Access for Terminal" "for DB in \"\$HOME/Library/Application Support/com.apple.TCC/TCC.db\" '/Library/Application Support/com.apple.TCC/TCC.db'; do echo \"== \$DB\"; sqlite3 \"\$DB\" \"select service, client, case auth_value when 2 then 'allowed' when 0 then 'denied' else auth_value end from access where auth_value=2 and service in ('kTCCServiceScreenCapture','kTCCServiceAccessibility','kTCCServiceSystemPolicyAllFiles','kTCCServiceCamera','kTCCServiceMicrophone','kTCCServiceListenEvent','kTCCServicePostEvent') order by service, client;\" 2>&1; done; echo; echo 'If you see an authorization error, grant Terminal Full Disk Access in System Settings > Privacy & Security and re-run.'"
sh_section "Trusted certificates: custom trust settings" "echo '-- admin'; security dump-trust-settings -d 2>&1 | grep -E 'Cert [0-9]+:|No Trust' ; echo '-- system'; security dump-trust-settings -s 2>&1 | grep -E 'Cert [0-9]+:|No Trust'; echo '-- user'; security dump-trust-settings 2>&1 | grep -E 'Cert [0-9]+:|No Trust'"
sh_section "Certificates in System keychain" "security find-certificate -a /Library/Keychains/System.keychain 2>/dev/null | grep '\"labl\"' | sort -u"
sh_section "Browser extensions: Chrome" "for M in \"\$HOME\"/Library/Application\\ Support/Google/Chrome/*/Extensions/*/*/manifest.json; do [ -f \"\$M\" ] || continue; ID=\$(echo \"\$M\" | awk -F/ '{print \$(NF-2)}'); N=\$(grep -m1 '\"name\"' \"\$M\" | sed 's/.*: *\"\\(.*\\)\".*/\\1/'); echo \"\$ID  \$N\"; done | sort -u"
sh_section "Browser extensions: Firefox" "for F in \"\$HOME\"/Library/Application\\ Support/Firefox/Profiles/*/extensions.json; do [ -f \"\$F\" ] && grep -o '\"defaultLocale\":{\"name\":\"[^\"]*' \"\$F\" | sed 's/.*\"name\":\"//' | sort -u; done"
sh_section "Browser extensions: Safari" "pluginkit -mAv -p com.apple.Safari.extension 2>&1"
sh_section "Saved Wi-Fi networks and VPNs" "networksetup -listpreferredwirelessnetworks en0 2>&1; echo; scutil --nc list 2>&1"
sh_section "SSD wear (smartctl, optional)" "if command -v smartctl >/dev/null; then smartctl -a disk0 2>&1 | head -60; else echo 'smartctl not installed. Optional: brew install smartmontools, then re-run.'; fi"
sh_section "Intel apps running under Rosetta (Apple silicon only)" "if [ \"\$(uname -m)\" = arm64 ]; then system_profiler SPApplicationsDataType 2>/dev/null | awk '/^    [^ ].*:\$/ {name=\$0} /Kind: Intel/ {print name}'; else echo 'Intel Mac, not applicable'; fi"
sh_section "Reboot history and boot time" "last reboot | head -8; sysctl kern.boottime"
sh_section "Fans and temperatures (needs sudo)" "if [ \"\$(id -u)\" = 0 ]; then powermetrics -n 1 -i 1000 --samplers thermal 2>&1 | head -20; powermetrics -n 1 -i 1000 --samplers smc 2>&1 | grep -iE 'fan|temp' | head -10; else echo 'Run with sudo to include this section.'; fi"
sh_section "Locale and time""date; systemsetup -gettimezone 2>&1; defaults read NSGlobalDomain AppleLanguages 2>/dev/null"
sh_section "Developer tools" "xcode-select -p 2>&1; git --version 2>&1; python3 --version 2>&1"

# ---------- Assemble ----------
{
  echo "# Mac checkup: $HOST"
  echo
  echo "- Format: 1"
  echo "- OS: macOS"
  echo "- Device type: $(echo "$SP_HW" | grep -q 'Model Name: MacBook' && echo laptop || echo desktop)"
  echo "- Generated: $(date)"
  echo "- Serial: $(echo "$SP_HW" | awk -F': ' '/Serial Number/ {print $2; exit}')"
  echo "- Hardware UUID: $(echo "$SP_HW" | awk -F': ' '/Hardware UUID/ {print $2; exit}')"
  echo "- Model: $(echo "$SP_HW" | awk -F': ' '/Model Name/ {print $2; exit}') ($(echo "$SP_HW" | awk -F': ' '/Model Identifier/ {print $2; exit}'))"
  echo "- Chip/CPU: $(echo "$SP_HW" | awk -F': ' '/Chip|Processor Name/ {print $2; exit}')"
  echo "- Memory: $(echo "$SP_HW" | awk -F': ' '/Memory:/ {print $2; exit}')"
  echo "- macOS: $(sw_vers -productVersion) ($(sw_vers -buildVersion))"
  echo "- Battery: ${CYCLES:-n/a} cycles, condition ${COND:-n/a}, max capacity ${MAXCAP:-n/a}"
  echo "- Free disk: ${FREE_PCT:-n/a}%"
  echo "- Warranty and coverage lookup: https://checkcoverage.apple.com (serial above)"
  echo
  echo "## Things to look at"
  echo
  if [ -s "$FLAGS" ]; then cat "$FLAGS"; else echo "- Nothing flagged automatically."; fi
  cat "$BODY"
  cat <<'EOF'

## Manual checklist (cannot be done by script)

Hardware
- [ ] Apple Diagnostics passed (Apple silicon: hold power until options appear, then Cmd+D. Intel: hold D at startup).
- [ ] Look up the serial at checkcoverage.apple.com (coverage, purchase date, AppleCare).
- [ ] System Settings > General > About: check repair history and any non-genuine parts warnings.
- [ ] Keyboard (every key), trackpad click, Touch ID, speakers, microphone, camera.
- [ ] Every port: charging, USB-C/Thunderbolt, headphone jack, SD slot.
- [ ] Screen: dead pixels, bright spots, burn-in (view solid white, black and red).
- [ ] Case: dents, swollen battery (trackpad lifting or case bulging), loose hinge, liquid damage.
- [ ] Stress test: run a video or heavy task for 10 minutes. Watch for fan noise, heat, throttling or shutdowns.

Handover and cleanup
- [ ] Activation Lock is off and no previous owner's Apple ID is signed in.
- [ ] No old user accounts, saved passwords, mail accounts or personal files left behind.
- [ ] Backup exists (Time Machine or other) before changing anything.
- [ ] Paid apps tied to the previous owner (Office, Adobe) are reassigned.
- [ ] For a clean start: sign out of iCloud, then System Settings > General > Transfer or Reset > Erase All Content and Settings.
EOF
} > "$OUT"

rm -f "$BODY" "$FLAGS"

echo
echo "Done. Report saved to:"
echo "  $OUT"
echo
pcc_upload_report "$OUT" || true
echo
echo "Optional: for sections that need admin rights (firmware password, some logs), re-run with: sudo bash mac-checkup.sh"

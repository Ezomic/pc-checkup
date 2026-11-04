#!/bin/bash
# Read-only Mac checkup. Writes a Markdown report to the Desktop.
# The scan runs once, only reads information, and installs and changes nothing: it sets no
# preference, starts no service, schedules nothing and leaves nothing running afterwards.
# The one exception is the opt-in --fix mode, which asks y/N before each change.
# Usage: bash mac-checkup.sh [--token TOKEN] [--code CODE] [--url URL] [--fix] [--benchmark]
#
# --fix walks the findings it can fix, shows the exact command for each and asks y/N (No by
# default). Everything it does is logged in the report ("Fix log") and in a text file next to it.
#
# --benchmark also runs short CPU, memory, disk and network speed tests (a minute or so, with a
# 512 MB temp file that is removed afterwards) and adds a Benchmark section to the report. The
# network tests need a token or scan code and are skipped without one.
#
# Optional upload: with a token the finished report is also sent to PC Checkup. The token is
# read from, in this order: --token, the PC_CHECKUP_TOKEN environment variable, or a file
# named pc-checkup.token next to this script. The site defaults to PC_DEFAULT_URL below;
# --url or PC_CHECKUP_URL override it (https only, plus http://localhost for testing).
# The token is never written to the report or printed.
#
# Without a token the script takes the 6 digit code of "Start a scan" in the app: --code, the
# PC_CHECKUP_CODE environment variable, or a prompt when run from a terminal (an empty answer
# skips the upload). A token always wins over a code. Bash 3.2 (stock macOS) compatible.

set -u

PCC_DEFAULT_URL="https://pc-checkup.thijssensoftware.nl"
PCC_SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)
PCC_TOKEN=""
PCC_TOKEN_SOURCE=""
PCC_CODE=""
PCC_CODE_SOURCE=""

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

# The app shows the code as "012 345", so spaces typed or pasted along with it are dropped.
pcc_clean_code() { pcc_clean "$1" | tr -d '[:space:]'; }

pcc_valid_code() { printf '%s' "$1" | grep -Eq '^[0-9]{6}$'; }

# Sets PCC_CODE and PCC_CODE_SOURCE (option or environment); both stay empty without one.
pcc_find_code() {
  local value
  PCC_CODE=""
  PCC_CODE_SOURCE=""
  value=$(pcc_clean_code "${1:-}")
  if [ -n "$value" ]; then PCC_CODE=$value; PCC_CODE_SOURCE=option; return 0; fi
  value=$(pcc_clean_code "${PC_CHECKUP_CODE:-}")
  if [ -n "$value" ]; then PCC_CODE=$value; PCC_CODE_SOURCE=environment; return 0; fi
  return 0
}

# Asks for the code when there is a terminal to ask on. An empty answer leaves PCC_CODE empty.
pcc_prompt_code() {
  local answer=""
  if [ ! -t 0 ] || [ ! -t 1 ]; then return 0; fi
  printf 'Scan code from the app, 6 digits (Enter to skip the upload): '
  IFS= read -r answer || answer=""
  answer=$(pcc_clean_code "$answer")
  if [ -n "$answer" ]; then PCC_CODE=$answer; PCC_CODE_SOURCE=prompt; fi
  return 0
}

pcc_valid_url() {
  printf '%s' "$1" | grep -Eiq '^(https://[A-Za-z0-9.-]+|http://(localhost|127\.0\.0\.1))(:[0-9]+)?(/[^[:space:]\\?#]*)?$'
}

# Prints {"markdown":"<file>"} with quotes, backslashes, control characters and non-ASCII
# text escaped, or {"code":"<$2>","markdown":"<file>"} when a 6 digit code is given as $2.
# Perl is always on a Mac; invalid UTF-8 becomes U+FFFD.
pcc_json_body() {
  /usr/bin/perl -MEncode -0777 -e '
    my $code = shift @ARGV;
    my $s = decode("UTF-8", scalar(<STDIN>) // "");
    $s =~ s/([\\"])/\\$1/g;
    $s =~ s/\n/\\n/g;
    $s =~ s/\r/\\r/g;
    $s =~ s/\t/\\t/g;
    $s =~ s/([\x00-\x1f])/sprintf("\\u%04x", ord($1))/ge;
    my $lead = (defined $code && $code =~ /\A[0-9]{6}\z/) ? "\"code\":\"$code\"," : "";
    print encode("UTF-8", "{" . $lead . "\"markdown\":\"" . $s . "\"}");
  ' "${2:-}" < "$1"
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

# Sends the report file ($1) to the site ($2) and prints one line saying what happened. With a
# token ($3) it goes to /api/reports; with an empty token and a scan code ($4) to /api/scans.
# The token only travels on curl's stdin config, never in its arguments.
pcc_upload() {
  local file=$1 base=$2 token=$3 code=${4:-} json resp err status rc line machine="" message="" errors="" path=/api/reports auth=""
  json=$(mktemp)
  resp=$(mktemp)
  err=$(mktemp)
  if [ -n "$token" ]; then
    auth=$(printf 'header = "Authorization: Bearer %s"\n' "$token")
    pcc_json_body "$file" > "$json"
  else
    path=/api/scans
    pcc_json_body "$file" "$code" > "$json"
  fi
  status=$(printf '%s\n' "$auth" | curl -sS -K - --max-time 30 -X POST \
    -H 'Content-Type: application/json; charset=utf-8' -H 'Accept: application/json' \
    --data-binary @"$json" -o "$resp" -w '%{http_code}' "$base$path" 2>"$err")
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
      429)
        if [ -n "$token" ]; then
          echo "Upload skipped: the server is rate limiting this token, try again in a minute."
        else
          echo "Upload skipped: too many wrong scan codes were tried recently, wait a while and start a new scan."
        fi ;;
      *) echo "Upload failed: the server answered HTTP $status." ;;
    esac
  fi
  rm -f "$json" "$resp" "$err"
}

# Called once the report is saved: works out the token and site, uploads, or explains how to.
pcc_upload_report() {
  local file=$1 base
  if [ -z "$PCC_TOKEN" ] && [ -z "$PCC_CODE" ]; then
    echo "To upload this report: choose Start a scan in the app and run again with --code (or PC_CHECKUP_CODE set), or create a token with only the Write ability (Settings, API tokens) and set PC_CHECKUP_TOKEN or put a pc-checkup.token file next to this script."
    return 0
  fi
  base=$(pcc_clean "${PCC_CLI_URL:-${PC_CHECKUP_URL:-$PCC_DEFAULT_URL}}")
  base=${base%/}
  if ! pcc_valid_url "$base"; then
    echo "Upload skipped: the site address must start with https:// (http://localhost is allowed for testing)."
  elif [ -n "$PCC_TOKEN" ]; then
    if pcc_valid_token "$PCC_TOKEN"; then
      pcc_upload "$file" "$base" "$PCC_TOKEN"
    else
      echo "Upload skipped: the token contains characters a token never has. Check the $PCC_TOKEN_SOURCE it came from."
    fi
    echo "Token safety: use a token with only the Write ability and a short lifetime, and delete the token from any machine that is not yours."
  elif pcc_valid_code "$PCC_CODE"; then
    pcc_upload "$file" "$base" "" "$PCC_CODE"
  else
    echo "Upload skipped: a scan code is exactly 6 digits. Check the $PCC_CODE_SOURCE it came from."
  fi
}

# ---------- Fix mode helpers (--fix) ----------
PCC_FIX_IDS="firewall-off gatekeeper-off low-power-mode updates-pending caches-large filevault-off sip-disabled mdm-enrolled activation-lock battery-wear disk-smart"
PCC_FW_BIN="${PCC_FW_BIN:-/usr/libexec/ApplicationFirewall/socketfilterfw}"
PCC_CACHE_DIR="${PCC_CACHE_DIR:-${HOME:-}/Library/Caches}"
PCC_CACHE_MIN_MB="${PCC_CACHE_MIN_MB:-2048}"
PCC_FIX_LOG=""
PCC_CACHE_KB=0

pcc_fix_sudo() { if [ "$(id -u)" = 0 ]; then return 0; fi; printf 'sudo '; }

pcc_fix_kind() {
  case $1 in
    firewall-off | gatekeeper-off | low-power-mode | updates-pending | caches-large) echo fix ;;
    *) echo advice ;;
  esac
}

pcc_fix_title() {
  case $1 in
    firewall-off) echo "Application firewall is off" ;;
    gatekeeper-off) echo "Gatekeeper is disabled" ;;
    low-power-mode) echo "Low Power Mode is on" ;;
    updates-pending) echo "Software updates are pending" ;;
    caches-large) echo "User caches are large" ;;
    filevault-off) echo "FileVault is off" ;;
    sip-disabled) echo "System Integrity Protection is disabled" ;;
    mdm-enrolled) echo "Enrolled in MDM" ;;
    activation-lock) echo "Activation Lock is enabled" ;;
    battery-wear) echo "Battery is worn" ;;
    disk-smart) echo "Disk SMART status is not healthy" ;;
  esac
}

pcc_fix_command() {
  local s
  s=$(pcc_fix_sudo)
  case $1 in
    firewall-off) echo "${s}$PCC_FW_BIN --setglobalstate on" ;;
    gatekeeper-off) echo "${s}spctl --master-enable" ;;
    low-power-mode) echo "${s}pmset -a lowpowermode 0" ;;
    updates-pending) echo "${s}softwareupdate --install --all" ;;
    caches-large) echo "find \"$PCC_CACHE_DIR\" -mindepth 1 -maxdepth 1 -exec rm -rf {} +" ;;
  esac
}

pcc_fix_undo() {
  local s
  s=$(pcc_fix_sudo)
  case $1 in
    firewall-off) echo "${s}$PCC_FW_BIN --setglobalstate off" ;;
    gatekeeper-off) echo "${s}spctl --master-disable" ;;
    low-power-mode) echo "${s}pmset -a lowpowermode 1" ;;
    updates-pending) echo "none" ;;
    caches-large) echo "none" ;;
  esac
}

pcc_fix_advice() {
  case $1 in
    filevault-off) echo "Turn it on in System Settings > Privacy & Security > FileVault. This script never changes FileVault." ;;
    sip-disabled) echo "Re-enable it from Recovery with: csrutil enable. This script never touches SIP." ;;
    mdm-enrolled) echo "Only the organisation that manages this Mac can remove the enrolment. Ask them." ;;
    activation-lock) echo "Normal on your own Mac. On a Mac you are taking over, the previous owner has to remove it from their Apple ID (iCloud, Find My)." ;;
    battery-wear) echo "Battery wear cannot be fixed in software. Plan a battery replacement." ;;
    disk-smart) echo "Back up now. A failing disk cannot be repaired by a script; plan a replacement." ;;
  esac
}

pcc_fix_du_kb() { perl -e 'alarm shift; exec @ARGV' 30 du -sk "$1" 2>/dev/null | awk 'NR==1 {print $1}'; }

pcc_fix_present() {
  local value
  case $1 in
    firewall-off) "$PCC_FW_BIN" --getglobalstate 2>/dev/null | grep -qi "disabled" ;;
    gatekeeper-off) spctl --status 2>/dev/null | grep -q "disabled" ;;
    low-power-mode) pmset -g 2>/dev/null | grep -i lowpowermode | grep -q 1 ;;
    updates-pending)
      if [ -z "${UPDATES+x}" ]; then UPDATES=$(softwareupdate -l 2>&1); fi
      printf '%s\n' "$UPDATES" | grep -q "^\* Label" ;;
    caches-large)
      [ -d "$PCC_CACHE_DIR" ] || return 1
      value=$(pcc_fix_du_kb "$PCC_CACHE_DIR")
      PCC_CACHE_KB=${value:-0}
      [ "$PCC_CACHE_KB" -ge $((PCC_CACHE_MIN_MB * 1024)) ] 2>/dev/null ;;
    filevault-off) fdesetup status 2>/dev/null | grep -q "Off" ;;
    sip-disabled) csrutil status 2>/dev/null | grep -q "disabled" ;;
    mdm-enrolled) profiles status -type enrollment 2>/dev/null | grep -qi "MDM enrollment: Yes" ;;
    activation-lock) system_profiler SPHardwareDataType 2>/dev/null | grep -i "Activation Lock Status" | grep -qi "Enabled" ;;
    battery-wear)
      value=$(system_profiler SPPowerDataType 2>/dev/null)
      printf '%s\n' "$value" | awk -F': ' '
        /Cycle Count/ && $2 + 0 >= 800 {bad = 1}
        /Condition/ && $2 != "Normal" {bad = 1}
        /Maximum Capacity/ {c = $2; sub(/%/, "", c); if (c + 0 < 80) bad = 1}
        END {exit !bad}' ;;
    disk-smart)
      value=$(diskutil info / 2>/dev/null | awk -F': +' '/SMART Status/ {print $2; exit}')
      [ -n "$value" ] && [ "$value" != "Verified" ] && [ "$value" != "Not Supported" ] ;;
    *) return 1 ;;
  esac
}

# stdin is closed for the fix itself so it can never swallow the answers meant for later prompts
pcc_fix_exec() { bash -c "$1" < /dev/null; }

pcc_fix_ask() {
  local answer=""
  printf 'Apply this fix? [y/N] '
  IFS= read -r answer || answer=""
  printf '\n'
  case $answer in y | Y | yes | YES | Yes) return 0 ;; *) return 1 ;; esac
}

pcc_fix_record() { printf -- '- %s | %s | %s | undo: %s\n' "$1" "$2" "$(pcc_oneline "$3")" "$(pcc_oneline "$4")" >> "$PCC_FIX_LOG"; }

pcc_fix_preview_caches() {
  echo "  Size preview: $((PCC_CACHE_KB / 1024)) MB in $PCC_CACHE_DIR. Largest folders:"
  perl -e 'alarm shift; exec @ARGV' 30 du -sk "$PCC_CACHE_DIR"/* 2>/dev/null | sort -rn | head -5 | awk '{printf "    %d MB  %s\n", $1 / 1024, $2}'
  echo "  Only the contents of that cache folder are removed; apps rebuild them. No documents or other user files are touched."
}

pcc_fix_one() {
  local id=$1 command undo
  command=$(pcc_fix_command "$id")
  undo=$(pcc_fix_undo "$id")
  echo "  Can fix here. Command: $command"
  [ "$id" = caches-large ] && pcc_fix_preview_caches
  case $command in sudo\ *) echo "  This needs administrator rights; sudo will ask for your password itself." ;; esac
  echo "  Undo: $undo"
  if ! pcc_fix_ask; then
    pcc_fix_record "$id" skipped "$command" "$undo"
    echo "  Skipped."
  elif pcc_fix_exec "$command"; then
    pcc_fix_record "$id" applied "$command" "$undo"
    echo "  Applied."
  else
    pcc_fix_record "$id" failed "$command" "$undo"
    echo "  Failed. Nothing more was changed for this finding."
  fi
}

pcc_fix_run() {
  local id found=0
  PCC_FIX_LOG=$(mktemp)
  echo
  echo "Fix mode: each fix shows its exact command and waits for y. Anything but y skips it."
  for id in $PCC_FIX_IDS; do
    pcc_fix_present "$id" || continue
    found=1
    echo
    if [ "$(pcc_fix_kind "$id")" = fix ]; then
      echo "[can fix here] $(pcc_fix_title "$id") ($id)"
      pcc_fix_one "$id"
    else
      echo "[needs you] $(pcc_fix_title "$id") ($id)"
      echo "  $(pcc_fix_advice "$id")"
    fi
  done
  [ "$found" = 1 ] || echo "Nothing to fix."
  return 0
}

pcc_fix_finish() {
  [ -n "$PCC_FIX_LOG" ] || return 0
  {
    echo "PC Checkup change log"
    echo "Report: $1"
    echo "Date: $(date)"
    echo
    if [ -s "$PCC_FIX_LOG" ]; then cat "$PCC_FIX_LOG"; else echo "No changes were made."; fi
  } > "$2"
  rm -f "$PCC_FIX_LOG"
  PCC_FIX_LOG=""
  echo "Change log saved to:"
  echo "  $2"
}

# ---------- Benchmark helpers (opt-in with --benchmark) ----------
# Fixed work sizes so numbers compare between runs; the PCC_BENCH_* defaults can be overridden
# in the environment, which the tests use to run the real functions on tiny sizes.
PCC_BENCHMARK=0
: "${PCC_BENCH_CPU_ITERATIONS:=15000000}"
: "${PCC_BENCH_MEM_MB:=64}"
: "${PCC_BENCH_MEM_COPIES:=24}"
: "${PCC_BENCH_DISK_MB:=512}"
: "${PCC_BENCH_NET_BYTES:=26214400}"
PCC_BENCH_FILE=""
PCC_BENCH_UPFILE=""

pcc_now_ms() { /usr/bin/perl -MTime::HiRes=time -e 'printf "%d\n", time() * 1000'; }

# Prints "- key: value" for a whole number, nothing otherwise, so a failed test leaves no line.
pcc_bench_line() {
  case ${2:-} in '' | *[!0-9]*) return 0 ;; esac
  printf -- '- %s: %s\n' "$1" "$2"
}

# Prints the "## Benchmark" block for the lines in file $1, nothing when the file is empty or missing.
pcc_benchmark_section() {
  [ -s "${1:-}" ] || return 0
  printf '\n## Benchmark\n\n'
  cat "$1"
}

pcc_bench_cleanup() {
  [ -n "$PCC_BENCH_FILE" ] && rm -f "$PCC_BENCH_FILE"
  [ -n "$PCC_BENCH_UPFILE" ] && rm -f "$PCC_BENCH_UPFILE"
  PCC_BENCH_FILE=""
  PCC_BENCH_UPFILE=""
  return 0
}

# Runs the same fixed loop in $1 processes at once and prints the milliseconds until all finish.
pcc_bench_cpu() {
  local procs=$1 start end i
  start=$(pcc_now_ms)
  for ((i = 0; i < procs; i++)); do
    /usr/bin/perl -e 'my $x = 0; for my $i (1 .. $ARGV[0]) { $x = ($x * 31 + $i) % 1000003 }' "$PCC_BENCH_CPU_ITERATIONS" &
  done
  wait
  end=$(pcc_now_ms)
  echo $((end - start))
}

pcc_bench_cpu_single() { pcc_bench_cpu 1; }

pcc_bench_cpu_multi() {
  local cores
  cores=$(sysctl -n hw.ncpu 2>/dev/null)
  case $cores in '' | *[!0-9]* | 0) cores=1 ;; esac
  pcc_bench_cpu "$cores"
}

# Copies a buffer in memory repeatedly and prints MB per second, timed inside perl.
pcc_bench_memory() {
  /usr/bin/perl -MTime::HiRes=time -e '
    my ($mb, $copies) = @ARGV;
    my $src = "x" x ($mb * 1048576);
    my $start = time;
    for (1 .. $copies) { my $dst = $src; substr($dst, 0, 1) = "y"; }
    my $secs = time - $start;
    $secs = 0.001 if $secs < 0.001;
    printf "%d\n", $mb * $copies / $secs;
  ' "$PCC_BENCH_MEM_MB" "$PCC_BENCH_MEM_COPIES"
}

# Prints MB per second for $PCC_BENCH_DISK_MB megabytes moved since the start time in $1.
pcc_bench_mb_s() {
  local start=$1 end ms
  end=$(pcc_now_ms)
  ms=$((end - start))
  [ "$ms" -ge 1 ] || ms=1
  echo $((PCC_BENCH_DISK_MB * 1000 / ms))
}

pcc_bench_disk_write() {
  local start
  start=$(pcc_now_ms)
  dd if=/dev/zero of="$1" bs=1048576 count="$PCC_BENCH_DISK_MB" 2>/dev/null || return 1
  sync
  pcc_bench_mb_s "$start"
}

# F_NOCACHE (macOS fcntl 48) makes the read hit the disk instead of the page cache, which
# purge cannot empty without root.
pcc_bench_disk_read() {
  local start
  start=$(pcc_now_ms)
  /usr/bin/perl -e 'open(my $fh, "<", $ARGV[0]) or exit 1; fcntl($fh, 48, 1); my $buf; while (read($fh, $buf, 1048576)) { }' "$1" || return 1
  pcc_bench_mb_s "$start"
}

# Prints the site address for the network tests, or nothing when it is not a usable one.
pcc_bench_base() {
  local base
  base=$(pcc_clean "${PCC_CLI_URL:-${PC_CHECKUP_URL:-$PCC_DEFAULT_URL}}")
  base=${base%/}
  pcc_valid_url "$base" || return 1
  printf '%s' "$base"
}

# Prints the curl config line that authenticates a benchmark request, or nothing without a
# usable token or scan code. The secret only travels on curl's stdin, never in its arguments.
pcc_bench_auth() {
  if [ -n "$PCC_TOKEN" ]; then
    pcc_valid_token "$PCC_TOKEN" || return 1
    printf 'header = "Authorization: Bearer %s"\n' "$PCC_TOKEN"
  elif [ -n "$PCC_CODE" ]; then
    pcc_valid_code "$PCC_CODE" || return 1
    printf 'header = "X-Scan-Code: %s"\n' "$PCC_CODE"
  else
    return 1
  fi
}

# Asks the site for one byte. Prints why the network tests cannot run and returns 1, or returns 0.
pcc_bench_net_check() {
  local base=$1 auth=$2 status
  status=$(printf '%s\n' "$auth" | curl -sS -K - --max-time 15 -o /dev/null -w '%{http_code}' "$base/api/bench/download?bytes=1" 2>/dev/null)
  case $status in
    200) return 0 ;;
    401) echo "the token or scan code was not accepted" ;;
    403) echo "the token does not have the Write ability" ;;
    429) echo "the server is rate limiting benchmark requests, try again in a minute" ;;
    000 | '') echo "$base could not be reached" ;;
    *) echo "the server answered HTTP $status" ;;
  esac
  return 1
}

# Prints megabits per second for a transfer of $1 bytes in $2 seconds.
pcc_bench_mbps() {
  awk -v b="$1" -v t="$2" 'BEGIN { if (t <= 0) t = 0.001; printf "%d\n", b * 8 / t / 1000000 }'
}

pcc_bench_net_down() {
  local base=$1 auth=$2 out code size secs
  out=$(printf '%s\n' "$auth" | curl -sS -K - --max-time 120 -o /dev/null -w '%{http_code} %{size_download} %{time_total}' "$base/api/bench/download?bytes=$PCC_BENCH_NET_BYTES" 2>/dev/null) || return 1
  read -r code size secs <<< "$out"
  [ "$code" = 200 ] && [ "$size" = "$PCC_BENCH_NET_BYTES" ] || return 1
  pcc_bench_mbps "$size" "$secs"
}

# $3 is a file of exactly $PCC_BENCH_NET_BYTES bytes to send.
pcc_bench_net_up() {
  local base=$1 auth=$2 file=$3 out code secs
  out=$(printf '%s\n' "$auth" | curl -sS -K - --max-time 120 -X POST -H 'Content-Type: application/octet-stream' --data-binary @"$file" -o /dev/null -w '%{http_code} %{time_total}' "$base/api/bench/upload" 2>/dev/null) || return 1
  read -r code secs <<< "$out"
  [ "$code" = 200 ] || return 1
  pcc_bench_mbps "$PCC_BENCH_NET_BYTES" "$secs"
}

# Runs one test: announces it, stores "- key: value" in file $1 when it gave a number.
# Args: file key title seconds command...
pcc_bench_step() {
  local out=$1 key=$2 title=$3 secs=$4 value
  shift 4
  printf '  %s (about %s seconds)... ' "$title" "$secs"
  value=$("$@")
  case $value in
    '' | *[!0-9]*) echo "failed, skipped." ;;
    *) echo "$value"; pcc_bench_line "$key" "$value" >> "$out" ;;
  esac
}

# Runs every test, writing the result lines to file $1. The temp files are removed even on Ctrl-C.
pcc_run_benchmark() {
  local out=$1 base auth reason upmb
  : > "$out"
  trap pcc_bench_cleanup EXIT
  trap 'pcc_bench_cleanup; exit 130' INT TERM
  echo
  echo "Benchmark mode: each test below runs on its own, so leave the Mac alone until it finishes."
  pcc_bench_step "$out" cpu_single_ms "CPU, one core, ms" 1 pcc_bench_cpu_single
  pcc_bench_step "$out" cpu_multi_ms "CPU, all cores, ms" 1 pcc_bench_cpu_multi
  pcc_bench_step "$out" memory_mb_s "Memory bandwidth, MB/s" 2 pcc_bench_memory
  PCC_BENCH_FILE=$(mktemp "${PCC_BENCH_DIR:-$HOME}/.pcc-bench.XXXXXX") || PCC_BENCH_FILE=""
  if [ -n "$PCC_BENCH_FILE" ]; then
    pcc_bench_step "$out" disk_write_mb_s "Disk write of ${PCC_BENCH_DISK_MB} MB, MB/s" 1 pcc_bench_disk_write "$PCC_BENCH_FILE"
    pcc_bench_step "$out" disk_read_mb_s "Disk read of ${PCC_BENCH_DISK_MB} MB, MB/s" 2 pcc_bench_disk_read "$PCC_BENCH_FILE"
  else
    echo "  Disk tests skipped: no temporary file could be created."
  fi
  pcc_bench_cleanup
  base=$(pcc_bench_base) || base=""
  auth=$(pcc_bench_auth) || auth=""
  upmb=$((PCC_BENCH_NET_BYTES / 1048576))
  if [ -z "$auth" ]; then
    echo "  Network tests skipped: they need a token or a scan code (use --code or --token)."
  elif [ -z "$base" ]; then
    echo "  Network tests skipped: the site address must start with https:// (http://localhost is allowed for testing)."
  elif ! reason=$(pcc_bench_net_check "$base" "$auth"); then
    echo "  Network tests skipped: $reason."
  else
    pcc_bench_step "$out" net_down_mbps "Network download of $upmb MB, Mbit/s" 5 pcc_bench_net_down "$base" "$auth"
    PCC_BENCH_UPFILE=$(mktemp "${PCC_BENCH_DIR:-$HOME}/.pcc-bench.XXXXXX") || PCC_BENCH_UPFILE=""
    if [ -n "$PCC_BENCH_UPFILE" ] && dd if=/dev/zero of="$PCC_BENCH_UPFILE" bs=1048576 count="$upmb" 2>/dev/null; then
      pcc_bench_step "$out" net_up_mbps "Network upload of $upmb MB, Mbit/s" 5 pcc_bench_net_up "$base" "$auth" "$PCC_BENCH_UPFILE"
    else
      echo "  Network upload skipped: no temporary file could be created."
    fi
    pcc_bench_cleanup
  fi
  trap - EXIT INT TERM
}

# ---------- Risk checks ----------
# Each check only reads. It prints facts for the report, plus "FLAG: text" lines for the
# things to look at, and says "could not check" instead of flagging when the data is missing
# or needs admin rights.
PCC_INSTALL_HISTORY="${PCC_INSTALL_HISTORY:-/Library/Receipts/InstallHistory.plist}"
PCC_BACKUP_MAX_DAYS=30
PCC_UPDATE_MAX_DAYS=60
PCC_ADMIN_FLAG_AT=3

# Whole days from a YYYY-MM-DD date (UTC) to today; prints nothing for anything else.
pcc_days_since() {
  printf '%s' "$1" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' || return 0
  /usr/bin/perl -MTime::Local -e '
    my ($y, $m, $d) = split /-/, $ARGV[0];
    my $then = timegm(0, 0, 0, $d, $m - 1, $y);
    printf "%d\n", (time - time % 86400 - $then) / 86400;
  ' "$1"
}

# Prints the newest Time Machine backup date (YYYY-MM-DD), or nothing when it cannot be read.
pcc_tm_last_date() {
  local when
  when=$(defaults read /Library/Preferences/com.apple.TimeMachine 2>/dev/null | awk '/SnapshotDates/ {on = 1} on && /\)/ {on = 0} on' | grep -Eo '[0-9]{4}-[0-9]{2}-[0-9]{2}' | sort | tail -n 1)
  if [ -z "$when" ]; then
    when=$(tmutil latestbackup 2>/dev/null | grep -Eo '[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}' | tail -n 1 | cut -c1-10)
  fi
  printf '%s' "$when"
}

# The data volume only exists from macOS 10.15; before that the boot volume is "/".
pcc_free_disk_pct() {
  local volume pct
  for volume in /System/Volumes/Data /; do
    pct=$(df -k "$volume" 2>/dev/null | awk 'NR==2 && $2 ~ /^[0-9]+$/ && $4 ~ /^[0-9]+$/ && $2 > 0 {printf "%d", ($4/$2)*100}')
    if [ -n "$pct" ]; then printf '%s' "$pct"; return 0; fi
  done
  return 0
}

pcc_backup_check() {
  local info last days
  if ! info=$(tmutil destinationinfo 2>&1); then
    echo "Time Machine: could not check (tmutil did not answer)"
    return 0
  fi
  case $info in
    *"No destinations configured"*)
      echo "Time Machine: not set up"
      echo "FLAG: No backup was found (Time Machine is not set up)."
      return 0 ;;
    *Name*) echo "Time Machine: set up" ;;
    *)
      echo "Time Machine: could not check (unexpected answer from tmutil)"
      return 0 ;;
  esac
  last=$(pcc_tm_last_date)
  days=$(pcc_days_since "$last")
  if [ -z "$days" ]; then
    echo "Last backup: could not check (the date is unreadable or needs Full Disk Access for Terminal)"
    return 0
  fi
  echo "Last backup: $last ($days days ago)"
  if [ "$days" -gt "$PCC_BACKUP_MAX_DAYS" ]; then echo "FLAG: The last backup was $days days ago (Time Machine)."; fi
}

# Something listening on a port is how Remote Login and Screen Sharing show up without admin rights.
pcc_remote_check() {
  local listening autologin
  if listening=$(netstat -an -p tcp 2>/dev/null); then
    listening=$(printf '%s\n' "$listening" | grep -v '127\.0\.0\.1\.' | grep -v '::1\.')
    if printf '%s\n' "$listening" | grep -Eq '[.:]22[[:space:]].*LISTEN'; then
      echo "Remote Login (SSH): on (something is listening on port 22)"
      echo "FLAG: Remote Login (SSH) is on, so other computers can try to sign in to this Mac."
    else
      echo "Remote Login (SSH): off"
    fi
    if printf '%s\n' "$listening" | grep -Eq '[.:]5900[[:space:]].*LISTEN'; then
      echo "Screen Sharing: on (something is listening on port 5900)"
      echo "FLAG: Screen Sharing is on, so other people on the network can ask to see or control this Mac."
    else
      echo "Screen Sharing: off"
    fi
  else
    echo "Remote Login (SSH): could not check (netstat did not answer)"
    echo "Screen Sharing: could not check (netstat did not answer)"
  fi
  autologin=$(defaults read /Library/Preferences/com.apple.loginwindow autoLoginUser 2>/dev/null)
  if [ -n "$autologin" ]; then
    echo "Automatic login: on (the account name is not recorded)"
    echo "FLAG: Automatic sign-in is on, so anyone who starts this computer gets in without a password."
  else
    echo "Automatic login: off"
  fi
}

# Newest macOS update from the install history. Background data updates (XProtect and the
# like) and App Store apps are left out, so they cannot hide an old system.
pcc_updates_check() {
  local newest days
  newest=$(plutil -convert xml1 -o - "$PCC_INSTALL_HISTORY" 2>/dev/null | awk '
    /<key>date<\/key>/ { getline; d = $0; gsub(/.*<date>|<\/date>.*/, "", d) }
    /<key>displayName<\/key>/ { getline; n = $0; gsub(/.*<string>|<\/string>.*/, "", n) }
    /<key>processName<\/key>/ {
      getline; p = $0; gsub(/.*<string>|<\/string>.*/, "", p)
      if (p == "softwareupdated" && n ~ /^(macOS|Security Update|Safari|Rapid Security|Command Line Tools)/ && substr(d, 1, 10) > best) best = substr(d, 1, 10)
    }
    END { print best }')
  days=$(pcc_days_since "$newest")
  if [ -z "$days" ]; then
    echo "Newest installed macOS update: could not check (no readable update history)"
    return 0
  fi
  echo "Newest installed macOS update: $newest ($days days ago)"
  if [ "$days" -gt "$PCC_UPDATE_MAX_DAYS" ]; then echo "FLAG: No macOS update has been installed for $days days (newest: $newest)."; fi
}

pcc_admins_check() {
  local members member count=0
  if ! members=$(dscl . -read /Groups/admin GroupMembership 2>/dev/null); then
    echo "Administrator accounts: could not check (dscl did not answer)"
    return 0
  fi
  for member in ${members#GroupMembership:}; do
    [ "$member" = root ] || count=$((count + 1))
  done
  echo "Administrator accounts: $count (not counting root)"
  if [ "$count" -ge "$PCC_ADMIN_FLAG_AT" ]; then echo "FLAG: $count accounts have administrator rights (see Administrator accounts section)."; fi
}

pcc_check_flags() { printf '%s\n' "$1" | sed -n 's/^FLAG: /- /p'; }

pcc_check_facts() { printf '%s\n' "$1" | grep -v '^FLAG: '; }

PCC_RUSTDESK_CONFIG="${PCC_RUSTDESK_CONFIG:-$HOME/Library/Preferences/com.carriez.RustDesk/RustDesk.toml}"

# RustDesk is never started or asked: only its id line is read, and no other line is kept or printed.
pcc_rustdesk_check() {
  local id=""
  if [ -r "$PCC_RUSTDESK_CONFIG" ]; then
    id=$(tr -d '\r' < "$PCC_RUSTDESK_CONFIG" | awk '/^\[/ {exit} {print}' | grep -E "^id[[:space:]]*=[[:space:]]*['\"][A-Za-z0-9_-]{4,64}['\"][[:space:]]*\$" | head -n 1 | sed -E "s/^id[[:space:]]*=[[:space:]]*['\"]([^'\"]*)['\"].*/\1/")
  fi
  if [ -n "$id" ]; then
    echo "RustDesk ID: $id"
  else
    echo "RustDesk ID: not found (RustDesk is not installed, or its settings file holds no readable ID)"
  fi
}

# Only reads who is running the scan: the sections that need sudo say so when it is not root.
pcc_admin_check() {
  if [ "$(id -u)" = 0 ]; then
    echo "Run as administrator: yes"
  else
    echo "Run as administrator: no, sections that need sudo say so"
  fi
}

# Sourcing this file (for tests) stops here instead of running the checkup.
if [ -n "${BASH_SOURCE[0]:-}" ] && [ "${BASH_SOURCE[0]}" != "$0" ]; then return 0; fi

PCC_CLI_TOKEN=""
PCC_CLI_URL=""
PCC_CLI_CODE=""
PCC_CLI_FIX=0
while [ $# -gt 0 ]; do
  case $1 in
    --code) [ $# -ge 2 ] || { echo "--code needs a value."; exit 2; }; PCC_CLI_CODE=$2; shift 2 ;;
    --code=*) PCC_CLI_CODE=${1#--code=}; shift ;;
    --token) [ $# -ge 2 ] || { echo "--token needs a value."; exit 2; }; PCC_CLI_TOKEN=$2; shift 2 ;;
    --token=*) PCC_CLI_TOKEN=${1#--token=}; shift ;;
    --url) [ $# -ge 2 ] || { echo "--url needs a value."; exit 2; }; PCC_CLI_URL=$2; shift 2 ;;
    --url=*) PCC_CLI_URL=${1#--url=}; shift ;;
    --fix) PCC_CLI_FIX=1; shift ;;
    --benchmark) PCC_BENCHMARK=1; shift ;;
    *) echo "Unknown option: $1. Usage: bash mac-checkup.sh [--token TOKEN] [--code CODE] [--url URL] [--fix] [--benchmark]"; exit 2 ;;
  esac
done
pcc_find_token "$PCC_CLI_TOKEN"
PCC_CLI_TOKEN=""
if [ -z "$PCC_TOKEN" ]; then
  pcc_find_code "$PCC_CLI_CODE"
  if [ -z "$PCC_CODE" ]; then pcc_prompt_code; fi
fi

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

FREE_PCT=$(pcc_free_disk_pct)
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

BOOT=$(sysctl -n kern.boottime | sed -n 's/^{ sec = \([0-9][0-9]*\),.*/\1/p')
if [ -n "${BOOT:-}" ]; then
    UPDAYS=$(( ( $(date +%s) - BOOT ) / 86400 ))
    [ "$UPDAYS" -ge 30 ] && flag "Not restarted for $UPDAYS days."
fi

SECURITY_TOOLS=$(ps -axco comm | grep -iE 'falcon|sophos|norton|mcafee|avast|avg|malwarebytes|jamf|kandji|crowdstrike|sentinel|defender|carbonblack|eset' | sort -u | tr '\n' ' ')
[ -n "${SECURITY_TOOLS:-}" ] && flag "Security or management agents running (can slow a Mac): $SECURITY_TOOLS"

ps -axco comm,pcpu | awk '$1 ~ /^(mds_stores|mds|mdworker_shared|photoanalysisd|backupd|softwareupdated)$/ && $2 > 20 {print $1}' | sort -u | tr '\n' ' ' | grep -q . && flag "Background indexing/backup process is using heavy CPU (Spotlight, Photos or Time Machine)."

CHECK_BACKUP=$(pcc_backup_check)
CHECK_REMOTE=$(pcc_remote_check)
CHECK_UPDATES=$(pcc_updates_check)
CHECK_ADMINS=$(pcc_admins_check)
{
  pcc_check_flags "$CHECK_BACKUP"
  pcc_check_flags "$CHECK_REMOTE"
  pcc_check_flags "$CHECK_UPDATES"
  pcc_check_flags "$CHECK_ADMINS"
} >> "$FLAGS"

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
sh_section "Performance: biggest folders in home" "END=\$((SECONDS+15)); for D in \"\$HOME\"/* \"\$HOME\"/Library/Caches; do R=\$((END-SECONDS)); [ \$R -gt 0 ] || break; perl -e 'alarm shift; exec @ARGV' \$R du -sh \"\$D\"; done 2>/dev/null | sort -rh | head -12; echo; echo '(capped at about 15 seconds; folders not reached in time are not listed)'"
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

section "Backup status" pcc_check_facts "$CHECK_BACKUP"
section "Remote exposure" pcc_check_facts "$CHECK_REMOTE"
section "Software update age" pcc_check_facts "$CHECK_UPDATES"
section "Administrator accounts" pcc_check_facts "$CHECK_ADMINS"
sh_section "User accounts" "dscl . list /Users | grep -v '^_'; echo; echo 'Admin group members:'; dscl . -read /Groups/admin GroupMembership 2>/dev/null; echo; echo 'Console user:'; stat -f%Su /dev/console"
sh_section "Logged in users and recent logins" "who; echo; last -10"

section "Software updates" echo "$UPDATES"
sh_section "Installed apps (/Applications)" "ls -1 /Applications; echo; echo '--- user apps ---'; ls -1 \"\$HOME/Applications\" 2>/dev/null"
sh_section "Installed apps with versions" "system_profiler SPApplicationsDataType -detailLevel mini | grep -E '^    [^ ]|Version:|Obtained from:' | head -300"
sh_section "Homebrew" "command -v brew >/dev/null && brew list --versions 2>/dev/null | head -100 || echo 'Homebrew not installed'"
sh_section "Configuration profiles and kernel/system extensions" "systemextensionsctl list 2>&1 | head -40; echo; kextstat 2>/dev/null | grep -v com.apple | head -40"
sh_section "Startup items: LaunchAgents and LaunchDaemons" "ls -1 /Library/LaunchAgents /Library/LaunchDaemons \"\$HOME/Library/LaunchAgents\" 2>/dev/null"
sh_section "Login items" "osascript -e 'tell application \"System Events\" to get the name of every login item' 2>&1"
sh_section "Background items (macOS 13+)" "perl -e 'alarm 30; exec @ARGV' sfltool dumpbtm 2>&1 | grep -E 'Name:|Developer Name:|Type:|Disposition:' | head -150; echo; echo '(capped at 30 seconds; if nothing is listed above, the command timed out or needs admin rights)'"
sh_section "Cron jobs" "crontab -l 2>&1"

sh_section "Listening network ports" "lsof -nP -iTCP -sTCP:LISTEN 2>/dev/null | head -40"
sh_section "DNS and proxy" "scutil --dns | head -25; scutil --proxy"
sh_section "Hosts file (non-default lines)" "grep -vE '^\s*#|^\s*$' /etc/hosts"

sh_section "Kernel panic reports" "ls -lt /Library/Logs/DiagnosticReports/*.panic 2>&1 | head -10; ls -lt /Library/Logs/DiagnosticReports 2>/dev/null | head -15"
sh_section "Recent shutdown causes" "perl -e 'alarm 20; exec @ARGV' log show --predicate 'eventMessage contains \"Previous shutdown cause\"' --last 7d 2>/dev/null | tail -8; echo; echo '(last 7 days, capped at 20 seconds)'"
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
sh_section "Locale and time" "date; systemsetup -gettimezone 2>&1; defaults read NSGlobalDomain AppleLanguages 2>/dev/null"
sh_section "Developer tools" "xcode-select -p 2>&1; git --version 2>&1; python3 --version 2>&1"

BENCH=$(mktemp)
if [ "$PCC_BENCHMARK" = 1 ]; then pcc_run_benchmark "$BENCH"; fi

# ---------- Fix mode ----------
if [ "$PCC_CLI_FIX" = 1 ]; then pcc_fix_run; fi

# ---------- Assemble ----------
{
  echo "# Mac checkup: $HOST"
  echo
  echo "This report comes from one scan that ran once, only read information, and installed and changed nothing on this Mac. Changes happen only in the optional --fix mode, one y/N question at a time, and are listed under Fix log."
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
  echo "- $(pcc_rustdesk_check)"
  echo "- $(pcc_admin_check)"
  echo "- Warranty and coverage lookup: https://checkcoverage.apple.com (serial above)"
  echo
  echo "## Things to look at"
  echo
  if [ -s "$FLAGS" ]; then cat "$FLAGS"; else echo "- Nothing flagged automatically."; fi
  cat "$BODY"
  if [ -n "$PCC_FIX_LOG" ] && [ -s "$PCC_FIX_LOG" ]; then
    echo
    echo "## Fix log"
    echo
    cat "$PCC_FIX_LOG"
  fi
  pcc_benchmark_section "$BENCH"
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

rm -f "$BODY" "$FLAGS" "$BENCH"
pcc_fix_finish "$OUT" "${OUT%.md}-changes.txt"

echo
echo "Done. Report saved to:"
echo "  $OUT"
echo
pcc_upload_report "$OUT" || true
echo
echo "Optional: for sections that need admin rights (firmware password, some logs), re-run with: sudo bash mac-checkup.sh"

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
# The storage step measures folder sizes and groups the user folder by kind of file, for at most
# 150 seconds. It stores sizes and counts, plus the names and sizes of the biggest folders in your
# user folder up to 4 levels deep (the 8 biggest, and inside a folder of 1 GB or more its 6 biggest
# of 100 MB or more), never a file name or a full path.
# It follows no links and leaves other disks alone. If the time runs out the report says the
# numbers are partial.
#
# The space step looks, for at most 60 seconds, at a few fixed places where space can be got back:
# the Trash, user caches, installers and disk images in Downloads, old iPhone and iPad backups
# (size and count, never a device name), the number of Time Machine snapshots, and Xcode build
# folders if they exist. It also notes the 10 biggest files of 50 MB or more in your user folder
# (file name, size and the name of the folder above it, never a full path), leaving out apps and
# everything in ~/Applications, because deleting part of an app breaks it. It deletes nothing.
#
# The apps step looks, for at most 45 seconds, at the apps in /Applications and ~/Applications that
# Spotlight says were not opened for 12 months or more. It stores the name, the size and the date of
# last use of at most 15 apps of 100 MB or more, never a path or a bundle id, and leaves out Apple's
# own apps and utilities. The backup step reads when Time Machine last ran and whether iCloud Drive
# keeps Desktop and Documents in sync. Neither step changes anything.
#
# The memory and drives step reads, for at most 15 seconds per system report, whether the memory can
# be upgraded, the size, type and speed of each memory slot and the kind, connection and size of each
# internal drive, so parts that fit can be bought. It never prints a serial number, part number or maker.
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
# PC_CHECKUP_CODE environment variable, or a prompt when run from a terminal. A token always wins
# over a code. Bash 3.2 (stock macOS) compatible.
#
# Pressing Enter at that prompt approves the upload with a QR code instead. Once the report is saved
# the script asks the site for a pairing (that request carries nothing about this Mac) and shows a
# QR code, a link and a 4 character check code. The owner opens it on a phone, checks the code and
# approves; only then is the report sent, with a token that works once. The script asks every 3
# seconds for at most 10 minutes; Ctrl-C stops it and nothing is sent. Without a terminal it never
# pairs.

set -u

PCC_DEFAULT_URL="https://pc-checkup.thijssensoftware.nl"
# The app swaps this marker for the version of this file when it serves the download.
PCC_SCRIPT_VERSION="__PCC_SCRIPT_VERSION__"
PCC_SCRIPT_NAME=$(basename "${BASH_SOURCE[0]:-mac-checkup.sh}")
case $PCC_SCRIPT_NAME in *.sh) ;; *) PCC_SCRIPT_NAME="mac-checkup.sh" ;; esac

pcc_banner() { echo "PC Checkup Mac script, version $PCC_SCRIPT_VERSION"; }

pcc_version_line() { echo "- Script version: $PCC_SCRIPT_VERSION"; }

# Asks the site which script version is current (one GET, nothing about this Mac) and, when it
# differs from this script, asks y/N. Returns 1 only when the answer is not yes. It never
# downloads or runs anything. Quiet, and PCC_UPDATE_STATUS stays "not checked", when it is
# switched off (PCC_SKIP_UPDATE_CHECK=1), nobody can answer, the request fails or the answer is odd.
PCC_UPDATE_STATUS="not checked"
pcc_update_check() {
  local base current answer
  PCC_UPDATE_STATUS="not checked"
  if [ "${PCC_SKIP_UPDATE_CHECK:-}" = "1" ]; then return 0; fi
  if [ ! -t 0 ] || [ ! -t 1 ]; then return 0; fi
  case $PCC_SCRIPT_VERSION in *[!0123456789abcdef]*) return 0 ;; esac
  if [ "${#PCC_SCRIPT_VERSION}" -ne 8 ]; then return 0; fi
  base=$(pcc_clean "${PCC_CLI_URL:-${PC_CHECKUP_URL:-$PCC_DEFAULT_URL}}")
  base=${base%/}
  if ! pcc_valid_url "$base"; then return 0; fi
  current=$(curl --silent --max-time 5 --max-filesize 64 "$base/scripts/version/mac" 2>/dev/null) || return 0
  case $current in *[!0123456789abcdef]*) return 0 ;; esac
  if [ "${#current}" -ne 8 ]; then return 0; fi
  if [ "$current" = "$PCC_SCRIPT_VERSION" ]; then PCC_UPDATE_STATUS="current"; return 0; fi
  echo
  echo "Er is een nieuwere versie van dit script beschikbaar."
  echo "A newer version of this script is available."
  echo "Download het hier / Download it here: $base/"
  printf 'Toch doorgaan? / Continue anyway? [y/N] '
  IFS= read -r answer || answer=""
  answer=$(pcc_clean "$answer" | tr '[:upper:]' '[:lower:]')
  case $answer in
    y|yes|j|ja) PCC_UPDATE_STATUS="outdated, continued"; return 0 ;;
  esac
  echo "Gestopt. Download het nieuwe script en start het opnieuw. / Stopped. Download the new script and run it again."
  return 1
}
PCC_SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)
PCC_TOKEN=""
PCC_TOKEN_SOURCE=""
PCC_CODE=""
PCC_CODE_SOURCE=""
PCC_PAIR=0

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

# Asks for the code when there is a terminal to ask on. An empty answer sets PCC_PAIR=1: the upload
# is then approved with a QR code once the report is saved.
pcc_prompt_code() {
  local answer=""
  if [ ! -t 0 ] || [ ! -t 1 ]; then return 0; fi
  echo "NL: typ de scancode uit de app (6 cijfers), of druk op Enter om de upload na de scan met een QR-code te laten goedkeuren."
  printf 'EN: scan code from the app, 6 digits, or Enter to approve the upload with a QR code after the scan: '
  IFS= read -r answer || return 0
  answer=$(pcc_clean_code "$answer")
  if [ -n "$answer" ]; then PCC_CODE=$answer; PCC_CODE_SOURCE=prompt; else PCC_PAIR=1; fi
  return 0
}

pcc_valid_url() {
  printf '%s' "$1" | grep -Eiq '^(https://[A-Za-z0-9.-]+|http://(localhost|127\.0\.0\.1))(:[0-9]+)?(/[^[:space:]\\?#]*)?$'
}

# Prints {"markdown":"<file>"} with quotes, backslashes, control characters and non-ASCII
# text escaped, or {"code":"<$2>","markdown":"<file>"} when a 6 digit code is given as $2, or
# {"upload_token":"<$3>","markdown":"<file>"} for the token of a QR approval.
# Perl is always on a Mac; invalid UTF-8 becomes U+FFFD.
pcc_json_body() {
  /usr/bin/perl -MEncode -0777 -e '
    my $code = shift @ARGV;
    my $upload = shift @ARGV;
    my $s = decode("UTF-8", scalar(<STDIN>) // "");
    $s =~ s/([\\"])/\\$1/g;
    $s =~ s/\n/\\n/g;
    $s =~ s/\r/\\r/g;
    $s =~ s/\t/\\t/g;
    $s =~ s/([\x00-\x1f])/sprintf("\\u%04x", ord($1))/ge;
    my $lead = (defined $code && $code =~ /\A[0-9]{6}\z/) ? "\"code\":\"$code\"," : "";
    $lead = "\"upload_token\":\"$upload\"," if defined $upload && $upload =~ /\A[A-Za-z0-9]{40}\z/;
    print encode("UTF-8", "{" . $lead . "\"markdown\":\"" . $s . "\"}");
  ' "${2:-}" "${3:-}" < "$1"
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
# token ($3) it goes to /api/reports; with an empty token and a scan code ($4), or the upload token
# of a QR approval ($5), to /api/scans. The token only travels on curl's stdin config, never in its
# arguments; the scan code and upload token only in the body.
pcc_upload() {
  local file=$1 base=$2 token=$3 code=${4:-} upload=${5:-} json resp err status rc line machine="" message="" errors="" path=/api/reports auth=""
  json=$(mktemp)
  resp=$(mktemp)
  err=$(mktemp)
  if [ -n "$token" ]; then
    auth=$(printf 'header = "Authorization: Bearer %s"\n' "$token")
    pcc_json_body "$file" > "$json"
  else
    path=/api/scans
    pcc_json_body "$file" "$code" "$upload" > "$json"
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

pcc_upload_help() {
  echo "To upload this report: choose Start a scan in the app and run again with --code (or PC_CHECKUP_CODE set), or create a token with only the Write ability (Settings, API tokens) and set PC_CHECKUP_TOKEN or put a pc-checkup.token file next to this script."
}

# Called once the report is saved: works out the token and site, uploads, or explains how to.
pcc_upload_report() {
  local file=$1 base
  if [ -z "$PCC_TOKEN" ] && [ -z "$PCC_CODE" ] && [ "$PCC_PAIR" != 1 ]; then
    pcc_upload_help
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
  elif [ -z "$PCC_CODE" ]; then
    pcc_pair_upload "$file" "$base"
  elif pcc_valid_code "$PCC_CODE"; then
    pcc_upload "$file" "$base" "" "$PCC_CODE"
  else
    echo "Upload skipped: a scan code is exactly 6 digits. Check the $PCC_CODE_SOURCE it came from."
  fi
}

# ---------- QR approval (Enter at the scan code prompt) ----------
PCC_PAIR_POLL_SECONDS=3
PCC_PAIR_MAX_SECONDS=600
PCC_PAIR_REQUEST_SECONDS=15

# One capped request to the pairing endpoints: the body goes to $1, the HTTP status is printed
# (000 without an answer). $4 is a curl config file, so the secret never shows in the arguments.
pcc_pair_http() {
  local out=$1 method=$2 url=$3 cfg=${4:-/dev/null}
  if [ "$method" = POST ]; then set -- -d ''; else set --; fi
  pcc_capped "$((PCC_PAIR_REQUEST_SECONDS + 5))" curl -sS -K "$cfg" --max-time "$PCC_PAIR_REQUEST_SECONDS" --max-filesize 65536 \
    -X "$method" ${1+"$@"} -H 'Accept: application/json' -o "$out" -w '%{http_code}' "$url" 2>/dev/null
}

# Prints the fields of a pairing answer ($1) as key=value lines, and qr= lines for the QR code, but
# only those with the shape the site gives them: nothing else from the answer reaches the terminal.
pcc_pair_fields() {
  /usr/bin/perl -MJSON::PP -0777 -e '
    my $d = eval { decode_json(scalar(<STDIN>) // "") };
    exit 0 unless ref $d eq "HASH";
    binmode(STDOUT, ":utf8");
    my %shape = (
      id => qr/\A[A-Za-z0-9]{24}\z/, secret => qr/\A[A-Za-z0-9]{40}\z/, check_code => qr/\A[A-Z0-9]{4}\z/,
      url => qr/\Ahttps?:\/\/[\x21-\x7e]{1,300}\z/, expires_in => qr/\A[0-9]{1,5}\z/,
      status => qr/\A[a-z]{1,20}\z/, upload_token => qr/\A[A-Za-z0-9]{40}\z/,
    );
    for my $k (sort keys %shape) {
      print "$k=$d->{$k}\n" if defined $d->{$k} && !ref $d->{$k} && $d->{$k} =~ $shape{$k};
    }
    my $qr = $d->{qr};
    if (ref $qr eq "ARRAY" && @$qr >= 10 && @$qr <= 100 && !grep { ref $_ || !/\A[ #\x{2580}\x{2584}\x{2588}]{1,200}\z/ } @$qr) {
      print "qr=$_\n" for @$qr;
    }
  ' < "$1"
}

# Prints the QR code ($1) dark on light whatever the terminal colours are. The ASCII version ($2=1)
# is painted with background colours, because # signs alone scan badly.
pcc_pair_print_qr() {
  if [ ! -t 1 ]; then cat "$1"; return 0; fi
  if [ "$2" = 1 ]; then
    /usr/bin/perl -pe 's/(#+)/"\e[48;5;16m" . (" " x length($1)) . "\e[48;5;231m"/ge; s/^/\e[48;5;231m/; s/$/\e[0m/' "$1"
  else
    /usr/bin/perl -pe 's/^/\e[38;5;16;48;5;231m/; s/$/\e[0m/' "$1"
  fi
}

# Asks the site for a pairing (nothing about this Mac is sent), shows its QR code, link and check
# code, then asks every 3 seconds, for at most 10 minutes, whether the owner approved it. Only then
# is the report ($1) sent to the site ($2), with the one-time upload token. Ctrl-C stops the wait.
# The 10 minutes are counted both in polls and on the clock, so a slow network cannot stretch them.
pcc_pair_upload() {
  local file=$1 base=$2 ascii=0 fmt=half resp cfg qr status line id="" secret="" check="" link="" expires="" result="" token="" waited=0 stop=0 started=$SECONDS
  case $(locale charmap 2>/dev/null) in UTF-8 | utf-8 | utf8) ;; *) ascii=1; fmt=ascii ;; esac
  resp=$(mktemp)
  cfg=$(mktemp)
  qr=$(mktemp)
  trap 'stop=1' INT
  trap 'rm -f "$resp" "$cfg" "$qr"; exit 143' TERM HUP
  status=$(pcc_pair_http "$resp" POST "$base/scripts/pair?qr=$fmt")
  if [ "$stop" = 0 ] && [ "$status" = 201 ]; then
    while IFS= read -r line; do
      case $line in
        id=*) id=${line#id=} ;;
        secret=*) secret=${line#secret=} ;;
        check_code=*) check=${line#check_code=} ;;
        url=*) link=${line#url=} ;;
        expires_in=*) expires=${line#expires_in=} ;;
        qr=*) printf '%s\n' "${line#qr=}" >> "$qr" ;;
      esac
    done <<< "$(pcc_pair_fields "$resp")"
  fi
  if [ "$stop" = 1 ] || [ -z "$id" ] || [ -z "$secret" ] || [ -z "$check" ] || [ -z "$link" ] || [ ! -s "$qr" ]; then
    trap - INT TERM HUP
    rm -f "$resp" "$cfg" "$qr"
    if [ "$stop" = 1 ]; then
      echo "NL: Gestopt, er is niets verstuurd."; echo "EN: Stopped, nothing was sent."
    else
      echo "Upload skipped: could not start a QR approval with $base (HTTP ${status:-000})."
    fi
    pcc_upload_help
    return 0
  fi
  if [ -z "$expires" ] || [ "$expires" -gt "$PCC_PAIR_MAX_SECONDS" ]; then expires=$PCC_PAIR_MAX_SECONDS; fi

  echo
  echo "NL: Laat de eigenaar deze QR-code scannen met een telefoon (of stuur een foto) en controleer samen dat de code $check is."
  echo "EN: Have the owner scan this QR code with a phone (or send a photo of it) and check together that the code is $check."
  echo
  pcc_pair_print_qr "$qr" "$ascii"
  echo
  echo "  $link"
  echo "  Code: $check"
  echo
  echo "NL: Wachten op goedkeuring, hooguit 10 minuten. Ctrl-C stopt zonder iets te versturen."
  echo "EN: Waiting for approval, up to 10 minutes. Ctrl-C stops without sending anything."

  printf 'header = "Authorization: Bearer %s"\n' "$secret" > "$cfg"
  while [ "$stop" = 0 ] && [ "$waited" -lt "$expires" ] && [ $((SECONDS - started)) -lt "$expires" ]; do
    status=$(pcc_pair_http "$resp" GET "$base/scripts/pair/$id" "$cfg")
    result=""
    if [ "$status" = 200 ]; then
      while IFS= read -r line; do
        case $line in
          status=*) result=${line#status=} ;;
          upload_token=*) token=${line#upload_token=} ;;
        esac
      done <<< "$(pcc_pair_fields "$resp")"
    elif [ "$status" = 404 ]; then
      result=expired
    fi
    case $result in
      approved) if [ -n "$token" ]; then break; fi ;;
      rejected | expired | used) break ;;
    esac
    if [ "$stop" = 0 ]; then sleep "$PCC_PAIR_POLL_SECONDS"; fi
    waited=$((waited + PCC_PAIR_POLL_SECONDS))
  done
  trap - INT TERM HUP
  rm -f "$resp" "$cfg" "$qr"

  echo
  case $stop:$result in
    1:*) echo "NL: Gestopt, er is niets verstuurd."; echo "EN: Stopped, nothing was sent." ;;
    0:approved)
      echo "NL: Goedgekeurd, het rapport wordt verstuurd."; echo "EN: Approved, sending the report."
      pcc_upload "$file" "$base" "" "" "$token"
      return 0 ;;
    0:rejected) echo "NL: De eigenaar heeft de upload afgewezen, er is niets verstuurd."; echo "EN: The owner rejected the upload, nothing was sent." ;;
    0:expired | 0:used) echo "NL: De goedkeuring is verlopen, er is niets verstuurd."; echo "EN: The approval request expired, nothing was sent." ;;
    *) echo "NL: Niemand heeft binnen 10 minuten goedgekeurd, er is niets verstuurd."; echo "EN: Nobody approved the upload within 10 minutes, nothing was sent." ;;
  esac
  pcc_upload_help
  return 0
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

# Runs "pcc_capped SECONDS COMMAND [ARGS]" in its own process group and relays its output. When the
# seconds run out the whole group is killed (a du under find included), the output pipes are
# dropped even if a process stuck on a dead mount cannot die, and the exit code is 142. When the
# command ends, anything it left running in the group is killed and its own exit code is returned.
# Ctrl-C, TERM, HUP or a closed reader stop the group too, and Ctrl-C ends the helper by the signal
# so the scan aborts. Limits: a process that starts its own session (setsid) is not in the group and
# can survive; stdout and stderr are relayed separately, so their interleaving is not kept; and the
# cap is not enforced while whatever reads the output is itself stalled. Core perl, 5.8 syntax.
PCC_CAP_PL='
use strict;
use warnings;
use POSIX ":sys_wait_h";
use Time::HiRes qw(time sleep);
my $secs = shift @ARGV;
my $deadline = time + $secs;
my (@rd, @wr);
for my $i (0, 1) { pipe($rd[$i], $wr[$i]) or exit 126; }
my $pid = fork;
exit 126 unless defined $pid;
if (!$pid) {
  setpgrp(0, 0);
  close $rd[$_] for 0, 1;
  open(STDIN, "<", "/dev/null");
  open(STDOUT, ">&", $wr[0]) or POSIX::_exit(126);
  open(STDERR, ">&", $wr[1]) or POSIX::_exit(126);
  close $wr[$_] for 0, 1;
  { no warnings; exec { $ARGV[0] } @ARGV; }
  POSIX::_exit(127);
}
close $wr[$_] for 0, 1;
sub stop { kill "KILL", -$pid; kill "KILL", $pid; }
for my $sig (qw(INT TERM HUP)) {
  $SIG{$sig} = sub { stop(); $SIG{$sig} = "DEFAULT"; kill $sig, $$; exit 128; };
}
$SIG{PIPE} = sub { stop(); exit 141 };
sub retry { return $!{EINTR} || $!{EAGAIN}; }
my @open = (0, 1);
my ($status, $timedout);
while (1) {
  if (!defined $status) {
    my $reaped = waitpid($pid, WNOHANG);
    if ($reaped == $pid) { $status = $?; }
    elsif ($reaped < 0) { $status = 0; }
  }
  my $left = $deadline - time;
  if ($left <= 0) { $timedout = 1; last; }
  if (!@open) {
    last if defined $status;
    sleep($left < 0.02 ? $left : 0.02);
    next;
  }
  my $rin = "";
  vec($rin, fileno($rd[$_]), 1) = 1 for @open;
  my $rout = $rin;
  my $wait = defined $status ? 0 : ($left < 0.05 ? $left : 0.05);
  my $n = select($rout, undef, undef, $wait);
  last if $n == 0 && defined $status;
  next if $n <= 0;
  my @still;
  for my $i (@open) {
    if (vec($rout, fileno($rd[$i]), 1)) {
      my $buf;
      my $got = sysread($rd[$i], $buf, 65536);
      if (!defined $got) { push @still, $i if retry(); next; }
      next if $got == 0;
      my $off = 0;
      while ($off < $got) {
        my $put = syswrite($i ? \*STDERR : \*STDOUT, $buf, $got - $off, $off);
        if (!defined $put) {
          if (retry()) { sleep 0.01; next; }
          last if $i;
          stop();
          exit($!{EPIPE} ? 141 : 1);
        }
        $off += $put;
      }
    }
    push @still, $i;
  }
  @open = @still;
}
stop();
exit 142 if $timedout;
exit(($status & 127) ? 128 + ($status & 127) : $status >> 8);
'
export PCC_CAP_PL
pcc_capped() { perl -e "$PCC_CAP_PL" "$@"; }
export -f pcc_capped

pcc_fix_du_kb() { pcc_capped 30 du -x -sk "$1" 2>/dev/null | awk 'NR==1 {print $1}'; }

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
  pcc_capped 30 du -x -sk "$PCC_CACHE_DIR"/* 2>/dev/null | sort -rn | head -5 | awk '{printf "    %d MB  %s\n", $1 / 1024, $2}'
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
# Oldest macOS major Apple still patches (the current major and the two before it). Apple went
# from 15 to 26, so count releases, not numbers. Each autumn, when a new major ships, raise this
# to the next major in the release list (26, 15, 14 today; after the next release 15 is the oldest).
PCC_OLDEST_SUPPORTED_MACOS=14

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
    when=$(pcc_capped 15 tmutil latestbackup 2>/dev/null | grep -Eo '[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}' | tail -n 1 | cut -c1-10)
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

pcc_os_check() {
  local major=${1%%.*}
  case "$major" in '' | *[!0-9]*) return 0 ;; esac
  if [ "$major" -lt "$PCC_OLDEST_SUPPORTED_MACOS" ]; then echo "FLAG: macOS $1 is old and likely out of security support."; fi
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

# Firmware settings are read from an allowlist of key names. nvram also holds the iCloud Find My
# sign-in token and other binary data, which must never reach a report, so nothing else is printed.
pcc_firmware_facts() {
  nvram -p 2>/dev/null | awk -F'\t' '
    $1 ~ /^(boot-args|auto-boot|prev-lang:kbd)$/ && tolower($2) !~ /bplist00|%[0-9a-f][0-9a-f]%[0-9a-f][0-9a-f]%[0-9a-f][0-9a-f]/ { print $1 ": " substr($2, 1, 200) }'
}

# Find My Mac is on when the key exists. Only the key name is tested, the value is never printed.
pcc_findmy_status() {
  local listing
  listing=$(nvram -p 2>/dev/null) || listing=""
  if [ -z "$listing" ]; then
    echo "Find My Mac: could not check"
  elif printf '%s\n' "$listing" | awk -F'\t' '$1 == "fmm-mobileme-token-FMM" { found = 1 } END { exit !found }'; then
    echo "Find My Mac: on"
  else
    echo "Find My Mac: off"
  fi
}

pcc_firmware_report() {
  system_profiler SPiBridgeDataType 2>/dev/null | head -30
  pcc_firmware_facts
}

pcc_findmy_report() {
  system_profiler SPHardwareDataType | grep -i 'activation lock'
  pcc_findmy_status
}

PCC_STORAGE_HOME_NAMES="Downloads|Documents|Desktop|Pictures|Movies|Music|Library|Applications"
PCC_STORAGE_LIBRARY_NAMES="Application Support|Caches|Containers|Group Containers|Mail|Messages|Mobile Documents|Developer|Logs|Safari"
PCC_STORAGE_ROOT_NAMES="Users|Applications|System|private|Library|usr|opt|var|bin|sbin"

pcc_storage_stop() {
  local slice=$(($2 * $3 / 100)) stop
  [ "$slice" -ge 1 ] || slice=1
  stop=$((SECONDS + slice))
  [ "$stop" -le $(($1 + $2)) ] || stop=$(($1 + $2))
  echo "$stop"
}

# Prints "KB<TAB>path" for every directory down to depth $1 under $3, in one pass, and appends the
# exit code to file $4: 142 means the $2 seconds ran out. du never follows symbolic links, and -x
# keeps it on the one disk. A du that the clock stopped may print nothing at all.
pcc_storage_du() {
  local depth=$1 secs=$2 dir=$3 rc_file=$4
  if [ "$secs" -lt 1 ]; then echo 142 >> "$rc_file"; return 0; fi
  pcc_capped "$secs" du -x -k -d "$depth" "$dir" 2>/dev/null
  echo $? >> "$rc_file"
}

# du buffers what it prints, so when the clock stops it the whole pass can come back empty. The
# folders a stopped pass did not report are then measured one at a time with du -s, each with an
# equal share of the time that is left. Reads directory names and prints "KB<TAB>path" lines.
pcc_storage_each() {
  local stop=$1 rc_file=$2 dir left
  shift 2
  left=$#
  for dir in "$@"; do
    pcc_storage_du 0 $(((stop - SECONDS + left - 1) / left)) "$dir" "$rc_file"
    left=$((left - 1))
  done
}

# Turns "KB<TAB>path" lines into root|Name|KB, one per top level folder, the biggest number winning.
# The data volume (macOS 10.15 and later) is firmlinked into the system volume, so for the folders
# that are whole firmlinks only its number counts. Mounted volumes and pseudo file systems are left out.
pcc_storage_rootrows() {
  LC_ALL=C awk -F '\t' -v root="${1%/}" -v data="${2%/}" -v hasdata="$3" -v whole="|$4" '
    BEGIN { split("Volumes dev net home cores Network", left, " "); for (i in left) skip[left[i]] = 1 }
    $1 ~ /^[0-9]+$/ {
      path = substr($0, length($1) + 2)
      fromdata = 0
      if (hasdata == 1 && index(path, data "/") == 1) {
        rel = substr(path, length(data) + 2)
        fromdata = 1
      } else if (index(path, root "/") == 1) {
        rel = substr(path, length(root) + 2)
      } else next
      if (rel == "" || index(rel, "/") > 0 || rel in skip) next
      if (!fromdata && hasdata == 1 && index(whole, "|" rel "|") > 0) next
      if (!(rel in best) || $1 + 0 > best[rel] + 0) best[rel] = $1
    }
    END {
      for (name in best) {
        clean = name
        gsub(/[^A-Za-z0-9 ._()+-]/, "_", clean)
        print "root|" substr(clean, 1, 40) "|" best[name]
      }
    }'
}

# Users without walking the user folder again: its size ($3 KB, folder $2 in $1) plus what du says
# about everything else in folder $1, within $4 seconds. Prints "KB<TAB>folder" when it finished.
pcc_storage_users() {
  local dir=$1 mine=$2 kb=$3 secs=$4 rc_file=$5 entry out="" rc
  set --
  for entry in "$dir"/* "$dir"/.[!.]* "$dir"/..?*; do
    if [ "${entry##*/}" = "$mine" ]; then continue; fi
    if [ -e "$entry" ] || [ -L "$entry" ]; then set -- "$@" "$entry"; fi
  done
  if [ "$#" -gt 0 ]; then
    if [ "$secs" -lt 1 ]; then echo 142 >> "$rc_file"; return 0; fi
    out=$(pcc_capped "$secs" du -x -k -d 0 "$@" 2>/dev/null)
    rc=$?
    echo "$rc" >> "$rc_file"
    if [ "$rc" = 142 ]; then return 0; fi
  fi
  printf '%s\n' "$out" | awk -F '\t' -v kb="$kb" -v dir="$dir" '$1 ~ /^[0-9]+$/ { kb += $1 } END { print kb "\t" dir }'
}

# The well-known folders one at a time, each with an equal share of the time left, Users through
# pcc_storage_users when the user folder $3 (of $4 KB) sits right inside it. Prints "KB<TAB>path" lines.
pcc_storage_roots_each() {
  local stop=$1 rc_file=$2 mine=$3 kb=$4 dir left secs
  shift 4
  left=$#
  for dir in "$@"; do
    secs=$(((stop - SECONDS + left - 1) / left))
    if [ -n "$mine" ] && [ -n "$kb" ] && [ "${dir##*/}" = Users ]; then
      pcc_storage_users "$dir" "$mine" "$kb" "$secs" "$rc_file"
    else
      pcc_storage_du 0 "$secs" "$dir" "$rc_file"
    fi
    left=$((left - 1))
  done
}

# The top of the system volume in one pass. When the pass is stopped, the well-known folders it did
# not report are measured one at a time in the order of PCC_STORAGE_ROOT_NAMES, and the ones that
# did not finish in their share get a second go with the time that is left. $5 is the name of the
# user folder when it sits right inside Users and $6 its size in KB, so Users does not walk it again.
# Prints root|Name|KB for every folder measured.
pcc_storage_roots() {
  local root=$1 data=$2 stop=$3 rc_file=$4 mine=$5 kb=$6 firm=${PCC_STORAGE_FIRMLINKS:-/usr/share/firmlinks}
  local whole="" hasdata=0 pass_rc pass_end rows more="" name dir round old_ifs=$IFS
  if [ -d "$data" ]; then
    hasdata=1
    whole=$(awk -F '\t' '$1 ~ /^\/[^\/]+$/ { print substr($1, 2) }' "$firm" 2>/dev/null | tr '\n' '|')
  fi
  pass_rc=$(mktemp)
  pass_end=$((SECONDS + ((stop - SECONDS) * 60 + 99) / 100))
  rows=$({
    if [ "$hasdata" = 1 ]; then pcc_storage_du 1 $((((pass_end - SECONDS) * 65 + 99) / 100)) "$data" "$pass_rc"; fi
    pcc_storage_du 1 $((pass_end - SECONDS)) "$root" "$pass_rc"
  } | pcc_storage_rootrows "$root" "$data" "$hasdata" "$whole")
  if grep -q '^142$' "$pass_rc"; then
    for round in 1 2; do
      set --
      IFS='|'
      for name in $PCC_STORAGE_ROOT_NAMES; do
        dir=""
        if printf '%s\n%s\n' "$rows" "$more" | grep -q "^root|$name|"; then continue; fi
        if [ "$hasdata" = 1 ] && [ -d "$data/$name" ] && [ ! -L "$data/$name" ] && case "|$whole" in *"|$name|"*) true ;; *) false ;; esac; then
          dir=${data%/}/$name
        elif [ -d "${root%/}/$name" ] && [ ! -L "${root%/}/$name" ]; then
          dir=${root%/}/$name
        fi
        if [ -n "$dir" ]; then set -- "$@" "$dir"; fi
      done
      IFS=$old_ifs
      if [ "$#" -eq 0 ] || [ "$SECONDS" -ge "$stop" ]; then break; fi
      more=$(printf '%s\n' "$more"; pcc_storage_roots_each "$stop" "$pass_rc" "$mine" "$kb" "$@" | pcc_storage_rootrows "$root" "$data" "$hasdata" "$whole")
    done
  fi
  cat "$pass_rc" >> "$rc_file"
  rm -f "$pass_rc"
  printf '%s\n%s\n' "$rows" "$more"
}

# Prints missing|root/Name for the well-known top level folders that exist but were not measured.
pcc_storage_missing_roots() {
  local root=$1 data=$2 measured=$3 dir name
  for dir in "${root%/}"/* "${data%/}"/*; do
    if [ ! -d "$dir" ] || [ -L "$dir" ]; then continue; fi
    name=${dir##*/}
    case "|$PCC_STORAGE_ROOT_NAMES|" in *"|$name|"*) ;; *) continue ;; esac
    printf '%s\n' "$measured" | grep -q "^root|$name|" || echo "missing|root/$name"
  done | sort -u
}

# Turns "KB<TAB>path" lines inside the user folder into home|KB, folder|Name|KB and library|Name|KB
# for the well-known names, and into the names the report shows: home|Name|KB for the 8 biggest
# folders directly inside the user folder and sub|Parent|Name|KB for the 5 biggest inside each of
# the 2 biggest of those. Then tree|Seg1/Seg2|KB lines, depth first: the same 8 folders, and inside
# any of them of 1 GiB or more its 6 biggest folders of 100 MiB or more, and so on, at most 4 levels
# deep. A name loses its control characters, pipes and slashes, has its spaces collapsed and is cut
# at 60 bytes without splitting a character, so "/" can join the names of a tree line. Of two
# folders that clean to the same name only the bigger is listed. Only folder names are ever kept,
# never a full path or a file name. A folder listed twice counts once, with the bigger number.
pcc_storage_homerows() {
  LC_ALL=C awk -F '\t' -v home="${1%/}" -v names="$PCC_STORAGE_HOME_NAMES" -v library="$PCC_STORAGE_LIBRARY_NAMES" '
    function tree(rel, lpath, depth,    count, i, name, best, key, label) {
      if (depth >= 4 || size[rel] + 0 < 1048576) return
      count = 0
      while (count < 6) {
        best = ""
        for (i = 1; i <= ntkids[rel]; i++) {
          name = tkids[rel, i]
          key = rel "/" name
          if (key in tused) continue
          if (best == "" || size[key] + 0 > size[rel "/" best] + 0 || (size[key] + 0 == size[rel "/" best] + 0 && name < best)) best = name
        }
        if (best == "" || size[rel "/" best] + 0 < 102400) break
        key = rel "/" best
        tused[key] = 1
        label = clean(best)
        if (label == "" || label == "." || label == ".." || ((lpath "/" label) in tseen)) continue
        tseen[lpath "/" label] = 1
        count++
        print "tree|" lpath "/" label "|" size[key]
        tree(key, lpath "/" label, depth + 1)
      }
    }
    function clean(s,    n, i, b, need) {
      gsub(/[\011-\015]/, " ", s)
      gsub(/[\001-\037\177]/, "", s)
      gsub("[|/\\\\]", "", s)
      gsub(/  +/, " ", s)
      sub(/^ /, "", s)
      sub(/ $/, "", s)
      if (length(s) > 60) {
        s = substr(s, 1, 60)
        n = length(s)
        for (i = n; i >= 1 && i > n - 3; i--) {
          b = ord[substr(s, i, 1)]
          if (b >= 192) {
            need = (b >= 240) ? 4 : ((b >= 224) ? 3 : 2)
            if (n - i + 1 < need) s = substr(s, 1, i - 1)
            break
          }
          if (b < 128) break
        }
        sub(/ +$/, "", s)
      }
      return s
    }
    BEGIN {
      for (i = 1; i < 256; i++) ord[sprintf("%c", i)] = i
      nknown = split(names, knownlist, "|"); for (i = 1; i <= nknown; i++) known[knownlist[i]] = 1
      nlib = split(library, liblist, "|"); for (i = 1; i <= nlib; i++) inside[liblist[i]] = 1
      total = -1
    }
    $1 ~ /^[0-9]+$/ {
      path = substr($0, length($1) + 2)
      if (path == home) { if ($1 + 0 > total) total = $1 + 0; next }
      if (index(path, home "/") != 1) next
      rel = substr(path, length(home) + 2)
      parts = split(rel, seg, "/")
      if (parts >= 1 && parts <= 4 && index(rel, SUBSEP) == 0 && index("/" rel "/", "//") == 0) {
        if (!(rel in size)) {
          up = substr(rel, 1, length(rel) - length(seg[parts]) - 1)
          ntkids[up]++
          tkids[up, ntkids[up]] = seg[parts]
        }
        if (!(rel in size) || $1 + 0 > size[rel] + 0) size[rel] = $1
      }
      if (parts == 1 && seg[1] != "") {
        if (!(seg[1] in one) || $1 + 0 > one[seg[1]] + 0) one[seg[1]] = $1
      } else if (parts == 2 && seg[1] != "" && seg[2] != "" && index(seg[1] seg[2], SUBSEP) == 0) {
        key = seg[1] SUBSEP seg[2]
        if (!(key in two) || $1 + 0 > two[key] + 0) two[key] = $1
      }
    }
    END {
      if (total >= 0) print "home|" total
      for (i = 1; i <= nknown; i++) if (knownlist[i] in one) print "folder|" knownlist[i] "|" one[knownlist[i]]
      for (i = 1; i <= nlib; i++) if (("Library" SUBSEP liblist[i]) in two) print "library|" liblist[i] "|" two["Library" SUBSEP liblist[i]]
      shown = 0
      while (shown < 8) {
        best = ""
        for (name in one) {
          if (name in used) continue
          if (best == "" || one[name] + 0 > one[best] + 0 || (one[name] + 0 == one[best] + 0 && name < best)) best = name
        }
        if (best == "" || one[best] + 0 < 1) break
        used[best] = 1
        label = clean(best)
        if (label == "" || label == "." || label == ".." || (label in seen)) continue
        seen[label] = 1
        shown++
        print "home|" label "|" one[best]
        topname[shown] = best
        toplabel[shown] = label
        if (shown <= 2) { parent[shown] = best; plabel[shown] = label; pindex[best] = shown }
      }
      for (key in two) {
        split(key, kk, SUBSEP)
        if (kk[1] in pindex) { p = pindex[kk[1]]; nkids[p]++; kids[p, nkids[p]] = kk[2] }
      }
      for (p = 1; p <= shown && p <= 2; p++) {
        count = 0
        while (count < 5) {
          best = ""
          for (i = 1; i <= nkids[p]; i++) {
            name = kids[p, i]
            if ((p SUBSEP name) in taken) continue
            key = parent[p] SUBSEP name
            if (best == "" || two[key] + 0 > two[parent[p] SUBSEP best] + 0 || (two[key] + 0 == two[parent[p] SUBSEP best] + 0 && name < best)) best = name
          }
          if (best == "" || two[parent[p] SUBSEP best] + 0 < 1) break
          taken[p SUBSEP best] = 1
          label = clean(best)
          if (label == "" || label == "." || label == ".." || ((p SUBSEP label) in seensub)) continue
          seensub[p SUBSEP label] = 1
          count++
          print "sub|" plabel[p] "|" label "|" two[parent[p] SUBSEP best]
        }
      }
      for (t = 1; t <= shown; t++) {
        print "tree|" toplabel[t] "|" one[topname[t]]
        tree(topname[t], toplabel[t], 1)
      }
    }'
}

# Prints the full path of every folder directly inside the user folder that a "KB<TAB>path" listing mentions.
pcc_storage_home_seen() {
  LC_ALL=C awk -F '\t' -v home="${1%/}" '
    $1 ~ /^[0-9]+$/ {
      path = substr($0, length($1) + 2)
      rest = substr(path, length(home) + 2)
      if (index(path, home "/") == 1 && rest != "" && index(rest, "/") == 0) print path
    }'
}

# Prints the full paths of the $2 biggest folders directly inside the user folder in a listing.
pcc_storage_home_biggest() {
  LC_ALL=C awk -F '\t' -v home="${1%/}" -v want="$2" '
    $1 ~ /^[0-9]+$/ {
      path = substr($0, length($1) + 2)
      rest = substr(path, length(home) + 2)
      if (index(path, home "/") == 1 && rest != "" && index(rest, "/") == 0 && $1 + 0 > 0 && (!(path in kb) || $1 + 0 > kb[path] + 0)) kb[path] = $1
    }
    END {
      for (round = 1; round <= want; round++) {
        best = ""
        for (path in kb) {
          if (path in used) continue
          if (best == "" || kb[path] + 0 > kb[best] + 0 || (kb[path] + 0 == kb[best] + 0 && path < best)) best = path
        }
        if (best == "") break
        used[best] = 1
        print best
      }
    }'
}

# What a stopped pass over the user folder did not report, measured one folder at a time: every
# folder directly inside it, the well-known ones inside Library, Library itself and the total last,
# then the inside of the two biggest folders, 3 levels down. The raw "KB<TAB>path" lines are
# appended to file $4.
pcc_storage_home_rest() {
  local home=${1%/} stop=$2 rc_file=$3 raw=$4 seen rows dir name left parents old_ifs=$IFS
  seen=$(mktemp)
  pcc_storage_home_seen "$home" < "$raw" > "$seen"
  rows=$(pcc_storage_homerows "$home" < "$raw")
  set --
  for dir in "$home"/* "$home"/.[!.]* "$home"/..?*; do
    if [ ! -d "$dir" ] || [ -L "$dir" ] || [ "${dir##*/}" = Library ]; then continue; fi
    grep -Fxq -- "$dir" "$seen" || set -- "$@" "$dir"
  done
  if [ -d "$home/Library" ] && [ ! -L "$home/Library" ]; then
    IFS='|'
    for name in $PCC_STORAGE_LIBRARY_NAMES; do
      if [ -d "$home/Library/$name" ] && [ ! -L "$home/Library/$name" ] && ! printf '%s\n' "$rows" | grep -q "^library|$name|"; then set -- "$@" "$home/Library/$name"; fi
    done
    IFS=$old_ifs
    grep -Fxq -- "$home/Library" "$seen" || set -- "$@" "$home/Library"
  fi
  if ! printf '%s\n' "$rows" | grep -q '^home|[0-9]*$'; then set -- "$@" "$home"; fi
  pcc_storage_each $((SECONDS + ((stop - SECONDS) * 70 + 99) / 100)) "$rc_file" "$@" >> "$raw"
  parents=$(pcc_storage_home_biggest "$home" 2 < "$raw")
  left=$(printf '%s\n' "$parents" | grep -c .)
  printf '%s\n' "$parents" | while IFS= read -r dir; do
    if [ -z "$dir" ]; then continue; fi
    if ! grep -Fq -- "$dir/" "$raw"; then pcc_storage_du 3 $(((stop - SECONDS + left - 1) / left)) "$dir" "$rc_file" >> "$raw"; fi
    left=$((left - 1))
  done
  rm -f "$seen"
}

# The user folder in one pass, four levels deep: its total and the folders in it down to that depth
# (du walks every level anyway, so the depth only adds lines, not time). When the pass is stopped,
# what it did not report is measured one folder at a time.
pcc_storage_home() {
  local home=$1 stop=$2 rc_file=$3 pass_rc pass_end raw
  [ -d "$home" ] || return 0
  pass_rc=$(mktemp)
  raw=$(mktemp)
  pass_end=$((SECONDS + ((stop - SECONDS) * 60 + 99) / 100))
  pcc_storage_du 4 $((pass_end - SECONDS)) "$home" "$pass_rc" > "$raw"
  if grep -q '^142$' "$pass_rc"; then pcc_storage_home_rest "$home" "$stop" "$pass_rc" "$raw"; fi
  cat "$pass_rc" >> "$rc_file"
  pcc_storage_homerows "$home" < "$raw"
  rm -f "$pass_rc" "$raw"
}

# Prints missing|home/Name and missing|library/Name for the well-known folders that exist but were not measured.
pcc_storage_missing_home() {
  local home=$1 measured=$2 name
  printf '%s\n' "$PCC_STORAGE_HOME_NAMES" | tr '|' '\n' | while IFS= read -r name; do
    if [ ! -d "$home/$name" ] || [ -L "$home/$name" ]; then continue; fi
    printf '%s\n' "$measured" | grep -q "^folder|$name|" || echo "missing|home/$name"
  done
  if [ -d "$home/Library" ] && [ ! -L "$home/Library" ]; then
    printf '%s\n' "$PCC_STORAGE_LIBRARY_NAMES" | tr '|' '\n' | while IFS= read -r name; do
      if [ ! -d "$home/Library/$name" ] || [ -L "$home/Library/$name" ]; then continue; fi
      printf '%s\n' "$measured" | grep -q "^library|$name|" || echo "missing|library/$name"
    done
  fi
}

# File names go through awk in memory only. What comes out is a total and a count per kind of file
# and per extension, never a name. find skips links and other disks, and cloud storage folders.
pcc_storage_types() {
  local home=$1 stop=$2 left rc_file rc
  [ -d "$home" ] || return 0
  left=$((stop - SECONDS))
  if [ "$left" -le 0 ]; then echo "capped|1"; return 0; fi
  rc_file=$(mktemp)
  {
    pcc_capped "$left" find "$home" -xdev -path "$home/Library/CloudStorage" -prune -o -type f -exec du -x -k {} + 2>/dev/null
    echo $? > "$rc_file"
  } | LC_ALL=C awk -F '\t' '
    function add(list, kind,    n, parts, i) {
      n = split(list, parts, " ")
      for (i = 1; i <= n; i++) group[parts[i]] = kind
    }
    BEGIN {
      add("jpg jpeg png gif heic heif bmp tif tiff webp raw cr2 nef arw dng", "Photos")
      add("mp4 mov avi mkv wmv m4v webm flv mpg mpeg 3gp", "Videos")
      add("mp3 m4a wav flac aac ogg wma aiff aif opus", "Music")
      add("pdf doc docx xls xlsx ppt pptx txt rtf odt ods odp csv pages numbers key md epub", "Documents")
      add("zip rar 7z tar gz tgz bz2 xz iso zst cab", "Archives")
      add("exe msi dmg pkg app ipa apk appx msix", "Apps")
      split("Photos Videos Music Documents Archives Apps Other", order, " ")
    }
    $1 ~ /^[0-9]+$/ {
      kb = $1
      path = substr($0, length($1) + 2)
      parts = split(path, segments, "/")
      pieces = split(segments[parts], dotted, ".")
      ext = ""
      if (pieces > 1 && !(pieces == 2 && dotted[1] == "")) ext = tolower(dotted[pieces])
      kind = (ext != "" && ext in group) ? group[ext] : "Other"
      kindKb[kind] += kb
      kindN[kind]++
      if (ext == "") {
        bareKb += kb
        bareN++
      } else if (ext ~ /^[a-z0-9]+$/ && length(ext) <= 10) {
        extKb[ext] += kb
        extN[ext]++
      }
    }
    END {
      for (i = 1; i <= 7; i++) {
        if (kindN[order[i]] > 0) print "type|" order[i] "|" kindKb[order[i]] "|" kindN[order[i]]
      }
      if (bareN > 0) print "noext|" bareKb "|" bareN
      for (round = 1; round <= 24; round++) {
        best = ""
        for (e in extKb) {
          if (e in shown) continue
          if (best == "" || extKb[e] > extKb[best] || (extKb[e] == extKb[best] && e < best)) best = e
        }
        if (best == "") break
        shown[best] = 1
        print "ext|" best "|" extKb[best] "|" extN[best]
      }
    }'
  rc=$(cat "$rc_file" 2>/dev/null)
  rm -f "$rc_file"
  if [ "$rc" = 142 ] || [ "$SECONDS" -ge "$stop" ]; then echo "capped|1"; fi
}

# Measures disk usage once, read only, and never longer than the cap (150 seconds by default).
# The user folder comes first, then the drive until three quarters of the cap, then the kinds of
# files; each phase hands on the time it did not use. Progress goes to the screen and the report
# text goes to stdout.
pcc_storage_report() {
  local home=${PCC_STORAGE_HOME:-$HOME} root=${PCC_STORAGE_ROOT:-/} data=${PCC_STORAGE_DATA:-/System/Volumes/Data}
  local cap=${PCC_STORAGE_CAP:-150} start=$SECONDS roots homes types rc_file scan="complete" mine="" kb
  case $cap in '' | *[!0-9]* | 0*) cap=150 ;; esac
  rc_file=$(mktemp)
  echo "Measuring storage (your user folder first, then the drive, then file types), stopping after $cap seconds at the latest..." >&2
  echo "  Measuring your user folder..." >&2
  homes=$(pcc_storage_home "$home" "$(pcc_storage_stop "$start" "$cap" 40)" "$rc_file" 2>/dev/null)
  kb=$(printf '%s\n' "$homes" | awk -F '|' '$1 == "home" && NF == 2 { print $2; exit }')
  case "$(dirname "$home")" in "${root%/}/Users" | "${data%/}/Users") mine=$(basename "$home") ;; esac
  echo "  Measuring the biggest folders on the drive..." >&2
  roots=$(pcc_storage_roots "$root" "$data" $((start + cap * 75 / 100)) "$rc_file" "$mine" "$kb" 2>/dev/null)
  echo "  Measuring kinds of files in your user folder..." >&2
  types=$(pcc_storage_types "$home" $((start + cap)) 2>/dev/null)
  if grep -q '^142$' "$rc_file" || printf '%s\n' "$types" | grep -q '^capped|'; then
    scan="partial (time limit of $cap seconds reached)"
    echo "  The time limit was reached, so the storage numbers are partial." >&2
  fi
  rm -f "$rc_file"
  echo "scan: $scan"
  echo "seconds: $((SECONDS - start))"
  echo "limit: $cap"
  echo "format: 2"
  printf '%s\n' "$roots" | grep '^root|' | sort -t '|' -k 3,3nr -k 2,2 | head -n 6
  if [ -d "$root" ]; then printf '%s\n' "$roots" | awk -F '|' '$1 == "root" && $3 ~ /^[0-9]+$/ { total += $3 } END { print "roots_total|" total + 0 }'; fi
  pcc_storage_missing_roots "$root" "$data" "$roots"
  printf '%s\n' "$homes" | grep -E '^(home|folder|library|sub|tree)\|'
  pcc_storage_missing_home "$home" "$homes"
  printf '%s\n' "$types" | grep -E '^(type|noext|ext)\|'
  return 0
}

# Counts what sits directly inside folder $1, for at most $2 seconds. More arguments go to find.
pcc_space_count() {
  local dir=$1 secs=$2 count
  shift 2
  if [ "$secs" -lt 1 ]; then echo 0; return 0; fi
  count=$(pcc_capped "$secs" find "$dir" -mindepth 1 -maxdepth 1 ! -name .DS_Store "$@" 2>/dev/null | wc -l | tr -d ' ')
  echo "${count:-0}"
}

# True unless the folder cannot be listed, as with the Trash or iPhone backups on a Mac that has not
# given Terminal Full Disk Access. A listing that is merely slow counts as readable.
pcc_space_readable() {
  local rc
  pcc_capped "$2" ls -f "$1" > /dev/null 2>&1
  rc=$?
  [ "$rc" = 0 ] || [ "$rc" = 142 ]
}

# Installers and disk images (dmg, pkg, iso) directly inside Downloads, as reclaim|installers|KB|count.
# du reports 0 for a file that only exists in the cloud, so those are left out.
pcc_space_installers() {
  local dir=$1 secs=$2 rc_file=$3
  if [ "$secs" -lt 1 ]; then echo 142 >> "$rc_file"; return 0; fi
  {
    pcc_capped "$secs" find "$dir" -maxdepth 1 -type f \( -iname '*.dmg' -o -iname '*.pkg' -o -iname '*.iso' \) -exec du -x -k {} + 2>/dev/null
    echo $? >> "$rc_file"
  } | awk -F '\t' '$1 ~ /^[0-9]+$/ && $1 > 0 { kb += $1; n++ } END { if (n > 0) print "reclaim|installers|" kb "|" n }'
}

# The number of Time Machine snapshots kept on this Mac. Their size is not known, only the count.
pcc_space_snapshots() {
  local secs=$1 rc_file=$2 out rc
  if [ "$secs" -lt 1 ]; then echo 142 >> "$rc_file"; return 0; fi
  out=$(pcc_capped "$secs" tmutil listlocalsnapshots / 2>/dev/null)
  rc=$?
  echo "$rc" >> "$rc_file"
  if [ "$rc" = 0 ]; then echo "snapshots|$(printf '%s\n' "$out" | grep -c 'com\.apple\.TimeMachine\.')"; fi
}

# Measures the well-known places one at a time, each with an equal share of the time that is left.
# Prints reclaim|place|KB|count for a place that exists, unreadable|place for one that cannot be
# listed, and snapshots|count. Nothing else is looked at.
pcc_space_places() {
  local home=$1 stop=$2 rc_file=$3 candidates present="" key dir left secs kb
  candidates="trash|$home/.Trash
caches|$home/Library/Caches
installers|$home/Downloads
backups|$home/Library/Application Support/MobileSync/Backup
derived|$home/Library/Developer/Xcode/DerivedData
devicesupport|$home/Library/Developer/Xcode/iOS DeviceSupport"
  while IFS='|' read -r key dir; do
    if [ -d "$dir" ] && [ ! -L "$dir" ]; then present="$present$key|$dir
"; fi
  done <<EOF
$candidates
EOF
  if command -v tmutil > /dev/null 2>&1; then present="${present}snapshots|/
"; fi
  left=$(printf '%s' "$present" | grep -c .)
  while IFS='|' read -r key dir; do
    [ -n "$key" ] || continue
    secs=$(((stop - SECONDS + left - 1) / left))
    left=$((left - 1))
    if [ "$key" != snapshots ] && [ "$secs" -ge 1 ] && ! pcc_space_readable "$dir" "$secs"; then
      echo "unreadable|$key"
      continue
    fi
    case $key in
      installers) pcc_space_installers "$dir" "$secs" "$rc_file" ;;
      snapshots) pcc_space_snapshots "$secs" "$rc_file" ;;
      *)
        kb=$(pcc_storage_du 0 "$secs" "$dir" "$rc_file" | awk -F '\t' '$1 ~ /^[0-9]+$/ { print $1; exit }')
        if [ -n "$kb" ]; then
          if [ "$key" = backups ]; then
            echo "reclaim|$key|$kb|$(pcc_space_count "$dir" $((stop - SECONDS)) -type d)"
          else
            echo "reclaim|$key|$kb|$(pcc_space_count "$dir" $((stop - SECONDS)))"
          fi
        fi
        ;;
    esac
  done <<EOF
$present
EOF
}

# The biggest single files under the user folder as file|name|KB|kind|folder: at most 10, each of at
# least $3 KB. Hidden items, Library, Applications, app and library packages, links and other disks
# are not entered, and a cloud-only file has no size on disk, so it drops out. The name
# and the folder directly above it are cleaned (control characters, pipes and slashes removed,
# 80 and 60 characters at most), and a full path is never printed.
pcc_space_files() {
  local home=$1 stop=$2 min_kb=$3 rc_file=$4 left escaped
  [ -d "$home" ] || return 0
  left=$((stop - SECONDS))
  if [ "$left" -le 0 ]; then echo 142 >> "$rc_file"; return 0; fi
  escaped=$(printf '%s' "$home" | sed -e 's/[][*?\\]/\\&/g')
  {
    pcc_capped "$left" find "$home" -mindepth 1 -xdev \( -path "$escaped/Library" -o -ipath "$escaped/Applications" -o -name '.*' -o \( -type d \( -name '*.app' -o -name '*.framework' -o -name '*.bundle' -o -name '*.plugin' -o -name '*.kext' -o -name '*.xpc' -o -name '*.appex' -o -name '*.photoslibrary' -o -name '*.musiclibrary' -o -name '*.tvlibrary' -o -name '*.imovielibrary' -o -name '*.fcpbundle' -o -name '*.sparsebundle' \) \) \) -prune -o -type f -size +"${min_kb}k" -exec du -x -k {} + 2>/dev/null
    echo $? >> "$rc_file"
  } | perl -e '
    use strict;
    use warnings;
    use Encode qw(decode encode);
    my ($home, $limit) = @ARGV;
    my %groups = (
      Photos => "jpg jpeg png gif heic heif bmp tif tiff webp raw cr2 nef arw dng",
      Videos => "mp4 mov avi mkv wmv m4v webm flv mpg mpeg 3gp",
      Music => "mp3 m4a wav flac aac ogg wma aiff aif opus",
      Documents => "pdf doc docx xls xlsx ppt pptx txt rtf odt ods odp csv pages numbers key md epub",
      Archives => "zip rar 7z tar gz tgz bz2 xz iso zst cab",
      Apps => "exe msi dmg pkg app ipa apk appx msix",
    );
    my %kind;
    for my $name (keys %groups) { $kind{$_} = $name for split / /, $groups{$name}; }
    sub clean {
      my ($text, $max) = @_;
      $text = decode("UTF-8", $text);
      $text =~ s{[\t\n\r\f\x0B\p{Zs}\p{Zl}\p{Zp}]+}{ }g;
      $text =~ s{[\p{Cc}/\\|]|(?![\x{200C}\x{200D}])\p{Cf}}{}g;
      $text =~ s{ {2,}}{ }g;
      $text =~ s{^ +| +$}{}g;
      $text = substr($text, 0, $max);
      $text =~ s{ +$}{};
      return undef if $text eq q() || $text eq q(.) || $text eq q(..);
      return encode("UTF-8", $text);
    }
    my @top;
    while (my $line = <STDIN>) {
      chomp $line;
      next unless $line =~ m{^(\d+)\t(.+)$};
      my ($kb, $path) = ($1, $2);
      next if $kb < 1 || index($path, "$home/") != 0 || !-f $path;
      push @top, [$kb, $path];
      @top = sort { $b->[0] <=> $a->[0] or $a->[1] cmp $b->[1] } @top;
      splice @top, $limit * 3 if @top > $limit * 3;
    }
    my $printed = 0;
    for my $item (@top) {
      last if $printed >= $limit;
      my @segments = split m{/}, substr($item->[1], length($home) + 1);
      next unless @segments;
      my $raw = pop @segments;
      my $name = clean($raw, 80);
      next unless defined $name;
      my $folder = @segments ? clean($segments[-1], 60) : undef;
      my $ext = $raw =~ m{^.+\.([^.]+)$} ? lc $1 : q();
      print join("|", "file", $name, $item->[0], $kind{$ext} // "Other", defined $folder ? $folder : q()), "\n";
      $printed++;
    }
  ' "${home%/}" 10
}

# Looks once, read only, in the well-known places where space can be got back, and for the biggest
# files, never longer than the cap (60 seconds by default). Progress goes to the screen and the
# report text goes to stdout.
pcc_space_report() {
  local home=${PCC_SPACE_HOME:-$HOME} cap=${PCC_SPACE_CAP:-60} min_kb=${PCC_SPACE_MIN_KB:-51200}
  local start=$SECONDS places files rc_file scan="complete"
  case $cap in '' | *[!0-9]* | 0*) cap=60 ;; esac
  case $min_kb in '' | *[!0-9]* | 0*) min_kb=51200 ;; esac
  rc_file=$(mktemp)
  echo "Looking for space you can get back, stopping after $cap seconds at the latest..." >&2
  echo "  Checking the Trash, caches, installers and backups..." >&2
  places=$(pcc_space_places "${home%/}" "$(pcc_storage_stop "$start" "$cap" 40)" "$rc_file" 2>/dev/null)
  echo "  Looking for the biggest files in your user folder..." >&2
  files=$(pcc_space_files "${home%/}" $((start + cap)) "$min_kb" "$rc_file" 2>/dev/null)
  if grep -q '^142$' "$rc_file"; then
    scan="partial (time limit of $cap seconds reached)"
    echo "  The time limit was reached, so these numbers are partial." >&2
  fi
  rm -f "$rc_file"
  echo "scan: $scan"
  echo "seconds: $((SECONDS - start))"
  echo "limit: $cap"
  echo "format: 1"
  printf '%s\n' "$places" | grep -E '^(reclaim|snapshots|unreadable)\|'
  printf '%s\n' "$files" | grep -E '^file\|'
  return 0
}

# The first 10 characters of $1 when they look like YYYY-MM-DD, otherwise nothing.
pcc_apps_date() {
  local day
  day=$(printf '%s' "$1" | cut -c1-10)
  if printf '%s' "$day" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'; then printf '%s' "$day"; fi
}

# The value of one attribute from mdls output ("name   = value"); prints nothing for (null).
pcc_apps_field() {
  local value
  value=$(printf '%s\n' "$1" | sed -n "s/^$2 *= *//p" | head -n 1 | tr -d '"')
  if [ "$value" != "(null)" ]; then printf '%s' "$value"; fi
}

# The date the bundle was last changed, as YYYY-MM-DD (UTC).
pcc_apps_mtime() {
  perl -e 'my @t = gmtime((stat $ARGV[0])[9]); printf "%04d-%02d-%02d", $t[5] + 1900, $t[4] + 1, $t[3]' "$1" 2> /dev/null
}

# Decides about one app bundle from Spotlight metadata. Prints the date it was last opened when that
# is on or before the cutoff, or "unknown" when Spotlight has no such date but the app is older than
# the cutoff, and nothing at all for Apple's own apps, for apps used since the cutoff and for newer
# apps. Returns 142 when mdls did not answer in time.
pcc_apps_decide() {
  local app=$1 cutoff=$2 info rc id used added
  info=$(pcc_capped 5 mdls -name kMDItemCFBundleIdentifier -name kMDItemLastUsedDate -name kMDItemDateAdded "$app" 2> /dev/null)
  rc=$?
  if [ "$rc" = 142 ]; then return 142; fi
  id=$(pcc_apps_field "$info" kMDItemCFBundleIdentifier)
  if [ -z "$id" ] && command -v defaults > /dev/null 2>&1; then id=$(defaults read "$app/Contents/Info" CFBundleIdentifier 2> /dev/null); fi
  case $id in com.apple.*) return 0 ;; esac
  used=$(pcc_apps_date "$(pcc_apps_field "$info" kMDItemLastUsedDate)")
  if [ -n "$used" ]; then
    if [[ "$used" > "$cutoff" ]]; then return 0; fi
    echo "$used"
    return 0
  fi
  added=$(pcc_apps_date "$(pcc_apps_field "$info" kMDItemDateAdded)")
  if [ -z "$added" ]; then added=$(pcc_apps_mtime "$app"); fi
  if [ -n "$added" ] && ! [[ "$added" > "$cutoff" ]]; then echo "unknown"; fi
  return 0
}

# Turns "KB<TAB>last<TAB>path" lines into app|name|KB|last, the biggest first and at most $2, then
# more|N for the apps that did not fit. The name is the bundle name without .app, cleaned of control
# characters, slashes and pipes, 60 characters at most. The path itself is never printed.
pcc_apps_rows() {
  perl -e '
    use strict;
    use warnings;
    use Encode qw(decode encode);
    my ($limit) = @ARGV;
    sub clean {
      my ($text) = @_;
      $text = decode("UTF-8", $text);
      $text =~ s{\.app$}{}i;
      $text =~ s{[\t\n\r\f\x0B\p{Zs}\p{Zl}\p{Zp}]+}{ }g;
      $text =~ s{[\p{Cc}/\\|]|(?![\x{200C}\x{200D}])\p{Cf}}{}g;
      $text =~ s{ {2,}}{ }g;
      $text =~ s{^ +| +$}{}g;
      $text = substr($text, 0, 60);
      $text =~ s{ +$}{};
      return undef if $text eq q() || $text eq q(.) || $text eq q(..);
      return encode("UTF-8", $text);
    }
    my %seen;
    while (my $line = <STDIN>) {
      chomp $line;
      next unless $line =~ m{^(\d+)\t([^\t]+)\t(.+)$};
      my ($kb, $last, $path) = ($1, $2, $3);
      my $name = clean((split m{/}, $path)[-1] // q());
      next unless defined $name;
      my $key = lc $name;
      $seen{$key} = [$kb, $last, $name] if !$seen{$key} || $seen{$key}->[0] < $kb;
    }
    my @apps = sort { $b->[0] <=> $a->[0] or $a->[2] cmp $b->[2] } values %seen;
    my $printed = 0;
    for my $app (@apps) {
      last if $printed >= $limit;
      print join("|", "app", $app->[2], $app->[0], $app->[1]), "\n";
      $printed++;
    }
    print "more|", scalar(@apps) - $printed, "\n" if @apps > $printed;
  ' "$1"
}

# Looks once, read only, at the apps in /Applications and ~/Applications that were not opened for 12
# months or more, using Spotlight metadata and the size of the bundle, never longer than the cap (45
# seconds by default). Apple's own apps, utilities and apps under 100 MB are left out; at most 15
# apps are listed. Prints the lines of the "Apps you may not need" section; progress goes to the screen.
pcc_apps_report() {
  local cap=${PCC_APPS_CAP:-45} min_kb=${PCC_APPS_MIN_KB:-102400} home=${PCC_APPS_HOME:-$HOME}
  local start=$SECONDS today cutoff scan="complete" dir app last rc secs kb list found
  case $cap in '' | *[!0-9]* | 0*) cap=45 ;; esac
  case $min_kb in '' | *[!0-9]* | 0*) min_kb=102400 ;; esac
  today=${PCC_TODAY:-$(date +%Y-%m-%d)}
  if ! printf '%s' "$today" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'; then today=$(date +%Y-%m-%d); fi
  cutoff="$((${today%%-*} - 1))-${today#*-}"
  list=$(mktemp)
  found=$(mktemp)
  trap 'rm -f "$list" "$found"; exit 130' INT TERM
  echo "Looking for apps you may not need, stopping after $cap seconds at the latest..." >&2
  for dir in "${PCC_APPS_DIR:-/Applications}" "${home%/}/Applications"; do
    [ -d "$dir" ] || continue
    pcc_capped 10 find "$dir" -maxdepth 2 \( -type d -name Utilities -prune \) -o \( -type d -name '*.app' -prune -print \) 2> /dev/null >> "$list"
    if [ $? = 142 ]; then scan="partial (time limit of $cap seconds reached)"; fi
  done
  while IFS= read -r app; do
    if [ $((SECONDS - start)) -ge "$cap" ]; then scan="partial (time limit of $cap seconds reached)"; break; fi
    [ -d "$app" ] || continue
    last=$(pcc_apps_decide "$app" "$cutoff")
    rc=$?
    if [ "$rc" = 142 ]; then scan="partial (time limit of $cap seconds reached)"; continue; fi
    [ -n "$last" ] || continue
    secs=$((cap - (SECONDS - start)))
    if [ "$secs" -lt 1 ]; then scan="partial (time limit of $cap seconds reached)"; break; fi
    if [ "$secs" -gt 20 ]; then secs=20; fi
    kb=$(pcc_capped "$secs" du -x -sk "$app" 2> /dev/null | awk 'NR==1 && $1 ~ /^[0-9]+$/ {print $1}')
    if [ -z "$kb" ]; then scan="partial (time limit of $cap seconds reached)"; continue; fi
    if [ "$kb" -ge "$min_kb" ]; then printf '%s\t%s\t%s\n' "$kb" "$last" "$app" >> "$found"; fi
  done < "$list"
  if [ "$scan" != "complete" ]; then echo "  The time limit was reached, so this list may be incomplete." >&2; fi
  echo "scan: $scan"
  echo "seconds: $((SECONDS - start))"
  echo "limit: $cap"
  echo "format: 1"
  echo "scanned: $today"
  pcc_apps_rows 15 < "$found"
  rm -f "$list" "$found"
  trap - INT TERM
  return 0
}

# Prints the folders (Desktop, Documents) that iCloud Drive keeps in sync, or "none". Nothing is
# printed when the preferences cannot be read.
pcc_backups_icloud() {
  local folders="" key name
  command -v defaults > /dev/null 2>&1 || return 0
  for key in Desktop Documents; do
    name=$(defaults read com.apple.finder "FXICloudDrive$key" 2> /dev/null)
    if [ "$name" = 1 ]; then folders="${folders:+$folders, }$key"; fi
  done
  echo "${folders:-none}"
}

# Facts about Time Machine and iCloud Drive for the "Backup freshness" section, one "key: value"
# per line. A fact that cannot be read is "could not check", never a guess. Nothing is changed.
pcc_backups_report() {
  local today=${PCC_TODAY:-$(date +%Y-%m-%d)} info last latest icloud
  if ! printf '%s' "$today" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'; then today=$(date +%Y-%m-%d); fi
  echo "format: 1"
  echo "scanned: $today"
  info=$(pcc_capped 15 tmutil destinationinfo 2>&1)
  case $info in
    *"No destinations configured"*)
      echo "timemachine: not set up"
      echo "timemachine-last: none" ;;
    *Name*)
      echo "timemachine: set up"
      last=$(pcc_tm_last_date)
      if [ -n "$last" ]; then
        echo "timemachine-last: $last"
      else
        latest=$(pcc_capped 15 tmutil latestbackup 2>&1)
        case $latest in
          *"No backups found"* | *"No machine directory found"*) echo "timemachine-last: none" ;;
          *) echo "timemachine-last: could not check" ;;
        esac
      fi ;;
    *)
      echo "timemachine: could not check"
      echo "timemachine-last: could not check" ;;
  esac
  icloud=$(pcc_backups_icloud)
  echo "icloud-folders: ${icloud:-could not check}"
  return 0
}

PCC_WIFI_CAP="${PCC_WIFI_CAP:-20}"
PCC_CRASH_CAP="${PCC_CRASH_CAP:-20}"
PCC_CRASH_DAYS="${PCC_CRASH_DAYS:-30}"
PCC_CRASH_USER_DIR="${PCC_CRASH_USER_DIR:-${HOME:-}/Library/Logs/DiagnosticReports}"
PCC_CRASH_SYSTEM_DIR="${PCC_CRASH_SYSTEM_DIR:-/Library/Logs/DiagnosticReports}"

# Signal strength (dBm) and band of the Wi-Fi connection in use right now, for the "Wi-Fi signal"
# section. Only the "Current Network Information" block is read, and only two of its values are
# printed: never the network name, other networks in range, saved networks or passwords. Nothing is
# printed for a value that cannot be read (including a system language other than English).
pcc_wifi_report() {
  local info rc
  echo "format: 1"
  info=$(pcc_capped "$PCC_WIFI_CAP" system_profiler SPAirPortDataType 2> /dev/null)
  rc=$?
  if [ "$rc" != 0 ]; then echo "status: could not check"; return 0; fi
  printf '%s\n' "$info" | awk '
    function indent(s) { match(s, /^ */); return RLENGTH }
    /^ *Current Network Information: *$/ { cur = indent($0); inside = 1; head = -1; next }
    inside && $0 !~ /^ *$/ {
      if (indent($0) <= cur) { inside = 0; next }
      if (head < 0) { head = indent($0); next }
      if (indent($0) <= head) { inside = 0; next }
    }
    !inside || $0 ~ /^ *$/ { next }
    dbm == "" && $0 ~ /^ *[^:]+: *-[0-9][0-9]* dBm *\/ *-?[0-9][0-9]* dBm *$/ {
      v = $0; sub(/^ *[^:]+: */, "", v); sub(/ *dBm.*$/, "", v); dbm = v
    }
    channel == "" && $0 ~ /^ *Channel: *[0-9][0-9]*( \([^)]*\))? *$/ {
      v = $0; sub(/^ *Channel: */, "", v)
      channel = v; sub(/[^0-9].*$/, "", channel)
      band = ""
      if (v ~ /\(/) { band = v; sub(/^[^(]*\(/, "", band); sub(/ *GHz.*$/, "", band); gsub(/,/, ".", band); gsub(/ /, "", band) }
      if (band != "2.4" && band != "5" && band != "6") {
        band = ""
        if (channel + 0 >= 1 && channel + 0 <= 14) band = "2.4"
        else if (channel + 0 >= 32 && channel + 0 <= 177) band = "5"
      }
    }
    END {
      if (dbm != "") print "signal-dbm: " dbm
      if (dbm != "" && band != "") print "band: " band " GHz"
      if (dbm == "") print "status: not connected or could not check"
    }'
}

# The apps that crashed most in the last PCC_CRASH_DAYS days, for the "Recent app crashes" section.
# Only the file names in the DiagnosticReports folders are read (never their contents), and only the
# app name in front of the date is kept: the host name that older reports carry after the date is
# dropped. Memory, reset and kernel reports are not app crashes and are skipped.
pcc_crashes_report() {
  local days=$PCC_CRASH_DAYS scan="complete" today list dir found=0 rc names total
  case $days in '' | *[!0-9]* | 0*) days=30 ;; esac
  today=${PCC_TODAY:-$(date +%Y-%m-%d)}
  if ! printf '%s' "$today" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'; then today=$(date +%Y-%m-%d); fi
  list=$(mktemp)
  for dir in "$PCC_CRASH_USER_DIR" "$PCC_CRASH_SYSTEM_DIR"; do
    [ -d "$dir" ] && [ -r "$dir" ] && [ -x "$dir" ] || continue
    found=1
    pcc_capped "$PCC_CRASH_CAP" find "$dir" -maxdepth 1 -type f \( -name '*.ips' -o -name '*.crash' \) -mtime -"$days" -print 2> /dev/null >> "$list"
    rc=$?
    if [ "$rc" = 142 ]; then scan="partial (time limit of $PCC_CRASH_CAP seconds reached)"; fi
  done
  echo "format: 1"
  echo "scanned: $today"
  echo "window-days: $days"
  if [ "$found" = 0 ]; then
    echo "scan: could not check"
    rm -f "$list"
    return 0
  fi
  names=$(sed -e 's|.*/||' "$list" \
    | sed -n -e 's/[-_][0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9].*$//p' \
    | tr -d '\000-\011\013-\037\177|' \
    | grep -viE '^(JetsamEvent|ResetCounter|Kernel|stacks|panic-full)$' \
    | grep -v '^[[:space:]]*$' \
    | cut -c1-80)
  echo "scan: $scan"
  total=$(printf '%s' "$names" | grep -c . )
  echo "total: ${total:-0}"
  if [ "${total:-0}" -gt 0 ]; then
    printf '%s\n' "$names" | sort | uniq -c | sort -k1,1nr -k2 | head -n 10 | awk '{ n = $1; sub(/^ *[0-9]+ /, ""); print "app|" $0 "|" n }'
  fi
  rm -f "$list"
  return 0
}

PCC_REMOTE_CAP="${PCC_REMOTE_CAP:-15}"
PCC_REMOTE_DIRS="${PCC_REMOTE_DIRS:-/Applications:${HOME:-}/Applications:/Library/LaunchDaemons:/Library/LaunchAgents}"
PCC_NETWORK_CAP="${PCC_NETWORK_CAP:-10}"
PCC_HOSTS_FILE="${PCC_HOSTS_FILE:-/etc/hosts}"
PCC_HOSTS_LINE_LIMIT=50000

# Known remote-access tools as "name|pattern". Only the name is ever printed. The app holds the same
# list of names.
pcc_remote_tools() {
  cat << 'EOF'
TeamViewer|teamviewer
AnyDesk|anydesk
RustDesk|rustdesk
Supremo|^supremo
ScreenConnect|screenconnect|connectwisecontrol
LogMeIn|logmein
Splashtop|splashtop
Chrome Remote Desktop|chrome remote desktop|chromoting|remoting_me2me_host
RealVNC|realvnc|^vnc (server|viewer)$
NoMachine|nomachine|^nxserver
Zoho Assist|zoho ?assist
DWService|dwservice|dwagent
HopToDesk|hoptodesk
GoTo Resolve|goto ?resolve|gotoassist
BeyondTrust|bomgar|beyondtrust remote support
Parsec|^parsecd?$
EOF
}

# Program names from "ps -axo comm=", which shows most programs with their full path. Programs in the
# macOS system folders are left out, because Apple's own parsecd (Siri and Spotlight Suggestions)
# runs on every Mac and is not Parsec. /usr/local is not a system folder. A program shown without a
# path keeps its name, also when ps puts it in brackets.
pcc_remote_program_names() {
  LC_ALL=C awk '
    { sub(/^[ \t]+/, ""); sub(/[ \t]+$/, "") }
    /^\(.*\)$/ { $0 = substr($0, 2, length($0) - 2) }
    /^\/(System|usr|bin|sbin|Library\/Apple)\// && !/^\/usr\/local\// { next }
    { sub(/.*\//, ""); print }
  '
}

# Sites whose redirection in the hosts file is a sign of hijacking. The app holds the same list.
PCC_WATCHED_SITES="google.com,google.nl,gmail.com,youtube.com,microsoft.com,live.com,outlook.com,office.com,microsoftonline.com,windowsupdate.com,apple.com,icloud.com,facebook.com,paypal.com,ing.nl,rabobank.nl,abnamro.nl,snsbank.nl,asnbank.nl,regiobank.nl,triodos.nl,knab.nl,bunq.com,digid.nl,belastingdienst.nl"

# The "Remote access tools" section: which known tools are installed (an app, or a launch item, in the
# folders of PCC_REMOTE_DIRS) or running. The other names are only compared in memory and never
# printed, so this is not an app inventory. LogMeIn Hamachi is a VPN, not remote control.
pcc_remote_report() {
  local installed running rc scan="complete" readable=0 dir line name pattern states old_ifs
  installed=$(mktemp)
  old_ifs=$IFS
  IFS=:
  set -- $PCC_REMOTE_DIRS
  IFS=$old_ifs
  for dir in ${1+"$@"}; do
    [ -n "$dir" ] && [ -d "$dir" ] && [ -r "$dir" ] && [ -x "$dir" ] || continue
    readable=1
    pcc_capped "$PCC_REMOTE_CAP" find "$dir" -mindepth 1 -maxdepth 1 -print 2> /dev/null >> "$installed"
    rc=$?
    if [ "$rc" = 142 ]; then scan="partial (time limit of $PCC_REMOTE_CAP seconds reached)"; elif [ "$rc" != 0 ]; then readable=0; break; fi
  done
  if [ "$readable" = 0 ]; then scan="partial (installed apps could not be read)"; fi
  running=$(pcc_capped "$PCC_REMOTE_CAP" ps -axo comm= 2> /dev/null)
  rc=$?
  if [ "$rc" = 142 ]; then scan="partial (time limit of $PCC_REMOTE_CAP seconds reached)"; fi
  echo "format: 1"
  if [ "$rc" != 0 ] && [ "$rc" != 142 ]; then
    if [ "$readable" = 0 ]; then
      echo "scan: could not check"
      rm -f "$installed"
      return 0
    fi
    scan="partial (running programs could not be read)"
  fi
  echo "scan: $scan"
  running=$(printf '%s\n' "$running" | pcc_remote_program_names | grep -iv hamachi)
  printf '%s\n' "$(sed -e 's|.*/||' -e 's|\.app$||' "$installed" | grep -iv hamachi)" > "$installed"
  while IFS= read -r line; do
    name=${line%%|*}
    pattern=${line#*|}
    states=""
    if grep -iEq "$pattern" "$installed"; then states="installed"; fi
    if printf '%s\n' "$running" | grep -iEq "$pattern"; then states="${states:+$states,}running"; fi
    if [ -n "$states" ]; then echo "tool|$name|$states"; fi
  done << EOF
$(pcc_remote_tools)
EOF
  rm -f "$installed"
  return 0
}

# Hosts file summary: how many host names it maps besides the defaults, and which of the watched
# sites it sends elsewhere (redirected) or to nowhere (blocked). No line of the file is printed.
pcc_hosts_summary() {
  local out rc
  if [ ! -r "$PCC_HOSTS_FILE" ]; then echo "hosts: could not check"; return 0; fi
  out=$(pcc_capped "$PCC_NETWORK_CAP" awk -v limit="$PCC_HOSTS_LINE_LIMIT" -v sites="$PCC_WATCHED_SITES" '
    BEGIN {
      n = split(sites, s, ",")
      split("localhost localhost.localdomain broadcasthost ip6-localhost ip6-loopback", d, " ")
      for (i in d) def[d[i]] = 1
    }
    NR > limit { partial = 1; exit }
    {
      gsub(/\r/, "")
      sub(/#.*/, "")
      if (NF < 2) next
      nowhere = (tolower($1) ~ /^(0\.0\.0\.0|127\.[0-9]+\.[0-9]+\.[0-9]+|::1?|::0)$/)
      for (f = 2; f <= NF; f++) {
        name = tolower($f)
        sub(/\.$/, "", name)
        if (name in def) continue
        entries++
        for (i = 1; i <= n; i++) {
          site = s[i]
          if (name != site && !(length(name) > length(site) && substr(name, length(name) - length(site)) == "." site)) continue
          if (nowhere && !(site in blocked)) { blocked[site] = 1; blist = blist (blist == "" ? "" : ", ") site }
          if (!nowhere && !(site in redirected)) { redirected[site] = 1; rlist = rlist (rlist == "" ? "" : ", ") site }
        }
      }
    }
    END {
      print (partial ? "hosts: partial (only the first " limit " lines were read)" : "hosts: complete")
      print "hosts-entries: " entries + 0
      if (rlist != "") print "hosts-redirected: " rlist
      if (blist != "") print "hosts-blocked: " blist
    }' "$PCC_HOSTS_FILE" 2> /dev/null)
  rc=$?
  if [ "$rc" != 0 ]; then echo "hosts: could not check"; return 0; fi
  printf '%s\n' "$out"
}

# Whether a proxy is set, with only its host and port (and only the host of a proxy script): never a
# user name, password or path.
pcc_proxy_summary() {
  local out rc
  out=$(pcc_capped "$PCC_NETWORK_CAP" scutil --proxy 2> /dev/null)
  rc=$?
  if [ "$rc" != 0 ]; then echo "proxy: could not check"; return 0; fi
  printf '%s\n' "$out" | awk '
    function clean(t) {
      sub(/^[A-Za-z][A-Za-z0-9+.-]*:\/\//, "", t)
      sub(/^.*@/, "", t)
      sub(/[\/?#].*$/, "", t)
      return ((t ~ /^[A-Za-z0-9._:-]+$/ || t ~ /^\[[0-9A-Fa-f:.]+\](:[0-9]+)?$/) && length(t) <= 100) ? t : ""
    }
    function at(kind) { return v[kind "Proxy"] (v[kind "Port"] != "" ? ":" v[kind "Port"] : "") }
    /^  [A-Za-z]+ : / { v[$1] = $3 }
    END {
      host = ""
      if (v["HTTPSEnable"] == "1" && v["HTTPSProxy"] != "") host = at("HTTPS")
      else if (v["HTTPEnable"] == "1" && v["HTTPProxy"] != "") host = at("HTTP")
      else if (v["SOCKSEnable"] == "1" && v["SOCKSProxy"] != "") host = at("SOCKS")
      on = (v["HTTPEnable"] == "1" || v["HTTPSEnable"] == "1" || v["SOCKSEnable"] == "1")
      script = ""
      if (v["ProxyAutoConfigEnable"] == "1") { on = 1; script = v["ProxyAutoConfigURLString"] }
      if (!on) { print "proxy: off"; exit }
      print "proxy: on"
      host = clean(host)
      script = clean(script)
      if (host != "") print "proxy-host: " host
      if (script != "") print "proxy-script-host: " script
    }'
}

pcc_dns_summary() {
  local out rc
  out=$(pcc_capped "$PCC_NETWORK_CAP" scutil --dns 2> /dev/null)
  rc=$?
  if [ "$rc" != 0 ]; then echo "dns: could not check"; return 0; fi
  echo "dns: complete"
  printf '%s\n' "$out" | awk '
    /^ *nameserver\[[0-9]+\] *: / {
      a = $NF
      if (a !~ /^[0-9A-Za-z:.%]+$/ || a in seen) next
      seen[a] = 1
      if (n < 8) { print "dns-server: " a; n++ }
    }'
}

# The "Network settings" section: the hosts file summary, the proxy and the DNS servers in use.
pcc_network_report() {
  echo "format: 1"
  pcc_hosts_summary
  pcc_proxy_summary
  pcc_dns_summary
  return 0
}

PCC_TRUST_CAP="${PCC_TRUST_CAP:-15}"

# The "Trusted certificates: custom trust settings" section: per trust domain, only how many
# certificates were given custom trust settings, never their names. "No Trust Settings" can come
# with a non-zero exit, so it counts as none rather than as a failure.
pcc_trust_report() {
  local domain out rc count
  echo "format: 1"
  for domain in admin system user; do
    case $domain in admin) set -- -d ;; system) set -- -s ;; *) set -- ;; esac
    out=$(pcc_capped "$PCC_TRUST_CAP" security dump-trust-settings ${1+"$@"} 2>&1)
    rc=$?
    count=$(printf '%s\n' "$out" | grep -c '^Cert [0-9][0-9]*:')
    if [ "$rc" = 0 ]; then
      echo "$domain: $count"
    elif [ "$rc" != 142 ] && [ "$count" = 0 ] && printf '%s\n' "$out" | grep -q 'No Trust Settings'; then
      echo "$domain: 0"
    else
      echo "$domain: could not check"
    fi
  done
  return 0
}

PCC_PARTS_CAP="${PCC_PARTS_CAP:-15}"
PCC_PARTS_MAX_MODULES=16
PCC_PARTS_MAX_DRIVES=8

# Reads "system_profiler SPMemoryDataType" on stdin: whether the memory can be upgraded and per slot its
# size, type and speed. Apple silicon lists no slots, only its unified memory and its type. Makers, part
# numbers and serial numbers are never printed, and a value of an unexpected shape is left blank.
pcc_parts_memory() {
  awk -v max="$PCC_PARTS_MAX_MODULES" '
    function indent(s) { match(s, /^ */); return RLENGTH }
    function value(s) { sub(/^[^:]*: */, "", s); gsub(/[\001-\037\177|]/, "", s); sub(/ +$/, "", s); return s }
    function flush() {
      if (slot != "" && n < max) { print "module|" slot "|" size "|" type "|" speed "|"; n++ }
      slot = ""
    }
    /^ *Memory Slots: *$/ { slots = indent($0); next }
    /^ *Upgradeable Memory: / { v = tolower(value($0)); if (v == "yes" || v == "no") print "memory-upgradeable: " v; next }
    slots != "" && /^ *[^ :][^:]*: *$/ && indent($0) > slots {
      flush()
      name = $0
      sub(/^ */, "", name); sub(/: *$/, "", name)
      gsub(/[\001-\037\177]/, " ", name); gsub(/\|/, "", name); gsub(/  +/, " ", name)
      slot = substr(name, 1, 40); sub(/ +$/, "", slot)
      size = ""; type = ""; speed = ""
      next
    }
    slot != "" && /^ *Size: / { v = value($0); if (v ~ /^[0-9]+([.,][0-9]+)? (MB|GB|TB)$/) size = v; else if (v == "Empty") size = "empty"; next }
    slot != "" && /^ *Type: / { v = value($0); if (v ~ /^[A-Za-z0-9]+( ECC)?$/ && length(v) <= 12 && v != "Empty") type = v; next }
    slot != "" && /^ *Speed: / { v = value($0); if (v ~ /^[0-9]+ MHz$/) speed = v; next }
    slots == "" && /^ +Memory: +[0-9]/ { unified = 1; next }
    slots == "" && /^ +Type: / { v = value($0); if (v ~ /^[A-Za-z0-9]+$/ && length(v) <= 12) utype = v; next }
    END {
      flush()
      if (unified && slots == "") { print "memory-unified: yes"; if (utype != "") print "memory-type: " utype }
    }'
}

# Reads "system_profiler SPNVMeDataType SPSerialATADataType" on stdin: per internal drive whether it is
# solid state or spinning, how it is connected (NVMe, SATA, or PCIe for the older Apple AHCI blades) and
# its size. Removable and detachable drives, volumes and controllers are skipped, and no drive name,
# model or serial number is printed.
pcc_parts_drives() {
  awk -v max="$PCC_PARTS_MAX_DRIVES" '
    function indent(s) { match(s, /^ */); return RLENGTH }
    function value(s) { sub(/^[^:]*: */, "", s); gsub(/[\001-\037\177|]/, "", s); sub(/ +$/, "", s); return s }
    function close_to(i) { while (depth > 0 && ind[stack[depth]] >= i) depth-- }
    /^[^ ][^:]*: *$/ { nvme = ($0 ~ /NVM/); depth = 0; next }
    /^ +[^ :][^:]*: *$/ {
      i = indent($0)
      close_to(i)
      n++; ind[n] = i; nv[n] = nvme; up[n] = depth > 0 ? stack[depth] : 0
      depth++; stack[depth] = n
      next
    }
    /^ +[^ :][^:]*: / {
      close_to(indent($0))
      if (depth == 0) next
      r = stack[depth]; key = $0; sub(/^ */, "", key); sub(/:.*$/, "", key); v = value($0)
      if (key == "Capacity") { sub(/ *\(.*$/, "", v); if (v ~ /^[0-9]+([.,][0-9]+)? (MB|GB|TB)$/) cap[r] = v }
      else if (key == "Medium Type") med[r] = (v == "Solid State") ? "SSD" : ((v == "Rotational") ? "HDD" : "")
      else if (key == "Model") model[r] = 1
      else if (key == "Physical Interconnect") link[r] = v
      else if ((key == "Detachable Drive" || key == "Removable Media") && v == "Yes") gone[r] = 1
    }
    END {
      for (r = 1; r <= n && shown < max; r++) {
        if (!model[r] || cap[r] == "" || gone[r]) continue
        l = link[r] != "" ? link[r] : link[up[r]]
        bus = nv[r] ? "NVMe" : (l ~ /^PCI/ ? "PCIe" : "SATA")
        print "drive|" (nv[r] ? "SSD" : med[r]) "|" bus "|" cap[r]
        shown++
      }
    }'
}

pcc_parts_status() {
  case $2 in
    0) echo "$1: complete" ;;
    142) echo "$1: partial (time limit of $PCC_PARTS_CAP seconds reached)" ;;
    *) echo "$1: could not check" ;;
  esac
}

# The "Memory and drives" section, for buying parts that fit. Each system report gets PCC_PARTS_CAP
# seconds; what was read before the cap is kept and flagged as partial.
pcc_parts_report() {
  local out rc
  echo "format: 1"
  out=$(pcc_capped "$PCC_PARTS_CAP" system_profiler SPMemoryDataType 2> /dev/null)
  rc=$?
  pcc_parts_status memory "$rc"
  if [ "$rc" = 0 ] || [ "$rc" = 142 ]; then printf '%s\n' "$out" | pcc_parts_memory; fi
  out=$(pcc_capped "$PCC_PARTS_CAP" system_profiler SPNVMeDataType SPSerialATADataType 2> /dev/null)
  rc=$?
  pcc_parts_status drives "$rc"
  if [ "$rc" = 0 ] || [ "$rc" = 142 ]; then printf '%s\n' "$out" | pcc_parts_drives; fi
  return 0
}

# git, python3 and the other developer commands in /usr/bin are stubs on a Mac without Xcode or the
# command line developer tools: running one opens a dialog that offers to install them. Prints the
# developer folder only when xcode-select names one that exists; run such a command only after that.
pcc_devtools_dir() {
  local dir
  dir=$(xcode-select -p 2> /dev/null) || return 1
  [ -d "$dir" ] || return 1
  printf '%s\n' "$dir"
}

# command -v only looks a name up, it never runs the command.
pcc_devtools_report() {
  local dir
  dir=$(pcc_devtools_dir) || { echo "Command line developer tools: not installed"; return 0; }
  printf '%s\n' "$dir"
  if command -v git > /dev/null 2>&1; then git --version 2>&1; else echo "git: not installed"; fi
  if command -v python3 > /dev/null 2>&1; then python3 --version 2>&1; else echo "python3: not installed"; fi
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
    *) echo "Unknown option: $1. Usage: bash $PCC_SCRIPT_NAME [--token TOKEN] [--code CODE] [--url URL] [--fix] [--benchmark]"; exit 2 ;;
  esac
done
echo "NL: Deze scan leest alleen hardware, schijfgebruik, map- en appnamen en beveiligingsinstellingen, wijzigt niets en verstuurt het rapport alleen als u een scancode invoert of de upload met een QR-code laat goedkeuren."; echo "EN: This scan only reads hardware, disk use, folder and app names and security settings, changes nothing, and uploads the report only if you enter a scan code or have the upload approved with a QR code. $PCC_DEFAULT_URL/privacy"
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
    "$@" 2>&1 | LC_ALL=C tr -d '\r'
    echo '```'
  } >> "$BODY"
}

sh_section() { section "$1" bash -c "$2"; }

pcc_banner
pcc_update_check || exit 1
echo "Running checkup, this can take a few minutes..."

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
TRUST_REPORT=$(pcc_trust_report)
printf '%s\n' "$TRUST_REPORT" | grep -q '^admin: [1-9]' && flag "Custom admin-trusted certificates are installed (see Trusted certificates section)."

echo "$UPDATES" | grep -q "^\* Label" && flag "Software updates are pending (see Software updates section)."

pcc_check_flags "$(pcc_os_check "$(sw_vers -productVersion)")" >> "$FLAGS"

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
STORAGE_BREAKDOWN=$(pcc_storage_report)
section "Storage breakdown" echo "$STORAGE_BREAKDOWN"
SPACE_REPORT=$(pcc_space_report)
section "Space you can get back" echo "$SPACE_REPORT"
APPS_REPORT=$(pcc_apps_report)
section "Apps you may not need" echo "$APPS_REPORT"
section "Memory pressure" memory_pressure
sh_section "Top processes by CPU" "ps -Arco pid,pcpu,pmem,comm | head -12"
sh_section "Top processes by memory" "ps -Amrco pid,pcpu,pmem,comm | head -12"
sh_section "Performance: load, swap, memory, throttling" "sysctl vm.loadavg vm.swapusage; pmset -g therm; pmset -g | grep -i lowpowermode; echo; vm_stat | head -12"
sh_section "Performance: 10 second CPU sample (second snapshot is the real one)" "top -l 2 -s 5 -n 12 -o cpu -stats pid,command,cpu,mem,state | awk '/^Processes:/ {n++} n==2'"
sh_section "Performance: Spotlight indexing" "mdutil -s / 2>&1; ps -axco pid,pcpu,comm | grep -E 'mds|mdworker' | head -5"
sh_section "Performance: disk write and read speed (512 MB test)" "T=\$(mktemp \"\$HOME/.speedtest.XXXXXX\"); dd if=/dev/zero of=\"\$T\" bs=1m count=512 2>&1 | tail -1; sync; purge 2>/dev/null; dd if=\"\$T\" of=/dev/null bs=1m 2>&1 | tail -1; rm -f \"\$T\"; echo; echo 'Healthy SSD: several hundred MB/s or more. Under ~100 MB/s means a failing or full disk.'"
sh_section "Performance: security and management agents" "ps -axco comm | grep -iE 'falcon|sophos|norton|mcafee|avast|avg|malwarebytes|jamf|kandji|crowdstrike|sentinel|defender|carbonblack|eset' | sort -u; echo; echo '(blank above means none found)'"
sh_section "Performance: login item and startup counts" "echo LaunchAgents/Daemons:; ls /Library/LaunchAgents /Library/LaunchDaemons \"\$HOME/Library/LaunchAgents\" 2>/dev/null | grep -v '^\$' | grep -vc '^/'"
sh_section "Performance: biggest folders in home" "END=\$((SECONDS+15)); for D in \"\$HOME\"/* \"\$HOME\"/Library/Caches; do R=\$((END-SECONDS)); [ \$R -gt 0 ] || break; pcc_capped \$R du -x -sh \"\$D\"; done 2>/dev/null | sort -rh | head -12; echo; echo '(capped at about 15 seconds; folders not reached in time are not listed)'"
section "Graphics and displays" system_profiler SPDisplaysDataType
section "Network interfaces" networksetup -listallhardwareports
section "Wi-Fi signal" pcc_wifi_report
section "Bluetooth" system_profiler SPBluetoothDataType
section "USB devices" system_profiler SPUSBDataType
section "Thunderbolt" system_profiler SPThunderboltDataType
section "Cameras and audio" system_profiler SPCameraDataType SPAudioDataType
section "Printers" system_profiler SPPrintersDataType

sh_section "Security: FileVault, SIP, Gatekeeper, firewall" "fdesetup status; csrutil status; spctl --status; /usr/libexec/ApplicationFirewall/socketfilterfw --getglobalstate; /usr/libexec/ApplicationFirewall/socketfilterfw --getstealthmode"
section "Security: secure boot and firmware" pcc_firmware_report
section "Activation Lock and Find My" pcc_findmy_report
sh_section "MDM and enrolment" "profiles status -type enrollment; echo; profiles list 2>&1 | head -40"
sh_section "Firmware password (Intel only)" "firmwarepasswd -check 2>&1 | head -5"
sh_section "Sharing and remote access" "systemsetup -getremotelogin 2>&1; systemsetup -getremoteappleevents 2>&1; launchctl print-disabled system 2>/dev/null | grep -iE 'ssh|screensharing|remotemanagement'"

section "Backup status" pcc_check_facts "$CHECK_BACKUP"
section "Backup freshness" pcc_backups_report
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
sh_section "Background items (macOS 13+)" "pcc_capped 30 sfltool dumpbtm 2>&1 | grep -E 'Name:|Developer Name:|Type:|Disposition:' | head -150; echo; echo '(capped at 30 seconds; if nothing is listed above, the command timed out or needs admin rights)'"
sh_section "Cron jobs" "crontab -l 2>&1"

sh_section "Listening network ports" "lsof -nP -iTCP -sTCP:LISTEN 2>/dev/null | head -40"
section "Remote access tools" pcc_remote_report
section "Network settings" pcc_network_report

sh_section "Kernel panic reports" "ls -lt /Library/Logs/DiagnosticReports/*.panic 2>&1 | head -10; ls -lt /Library/Logs/DiagnosticReports 2>/dev/null | head -15"
section "Recent app crashes" pcc_crashes_report
sh_section "Recent shutdown causes" "pcc_capped 20 log show --predicate 'eventMessage contains \"Previous shutdown cause\"' --last 7d 2>/dev/null | tail -8; echo; echo '(last 7 days, capped at 20 seconds)'"
sh_section "Time Machine" "tmutil destinationinfo 2>&1 | head -15; tmutil latestbackup 2>&1"
section "Trusted certificates: custom trust settings" echo "$TRUST_REPORT"
sh_section "Browser extensions: Chrome" "for M in \"\$HOME\"/Library/Application\\ Support/Google/Chrome/*/Extensions/*/*/manifest.json; do [ -f \"\$M\" ] || continue; ID=\$(echo \"\$M\" | awk -F/ '{print \$(NF-2)}'); N=\$(grep -m1 '\"name\"' \"\$M\" | sed 's/.*: *\"\\(.*\\)\".*/\\1/'); echo \"\$ID  \$N\"; done | sort -u"
sh_section "Browser extensions: Firefox" "for F in \"\$HOME\"/Library/Application\\ Support/Firefox/Profiles/*/extensions.json; do [ -f \"\$F\" ] && grep -o '\"defaultLocale\":{\"name\":\"[^\"]*' \"\$F\" | sed 's/.*\"name\":\"//' | sort -u; done"
sh_section "Browser extensions: Safari" "pluginkit -mAv -p com.apple.Safari.extension 2>&1"
sh_section "SSD wear (smartctl, optional)" "if command -v smartctl >/dev/null; then smartctl -a disk0 2>&1 | head -60; else echo 'smartctl not installed. Optional: brew install smartmontools, then re-run.'; fi"
sh_section "Intel apps running under Rosetta (Apple silicon only)" "if [ \"\$(uname -m)\" = arm64 ]; then system_profiler SPApplicationsDataType 2>/dev/null | awk '/^    [^ ].*:\$/ {name=\$0} /Kind: Intel/ {print name}'; else echo 'Intel Mac, not applicable'; fi"
sh_section "Reboot history and boot time" "last reboot | head -8; sysctl kern.boottime"
sh_section "Fans and temperatures (needs sudo)" "if [ \"\$(id -u)\" = 0 ]; then powermetrics -n 1 -i 1000 --samplers thermal 2>&1 | head -20; powermetrics -n 1 -i 1000 --samplers smc 2>&1 | grep -iE 'fan|temp' | head -10; else echo 'Run with sudo to include this section.'; fi"
sh_section "Locale and time" "date; systemsetup -gettimezone 2>&1; defaults read NSGlobalDomain AppleLanguages 2>/dev/null"
section "Developer tools" pcc_devtools_report
section "Memory and drives" pcc_parts_report

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
  pcc_version_line
  echo "- Update check: $PCC_UPDATE_STATUS"
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
echo "Optional: for sections that need admin rights (firmware password, some logs), re-run with: sudo bash $PCC_SCRIPT_NAME"

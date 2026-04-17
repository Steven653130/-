#!/bin/bash
# Backfill all LLM card fields (title + summary) for all languages
# Usage: bash backfill_all_langs.sh [batch_size] [max_batches]
# Example: bash backfill_all_langs.sh 50 20  # Run 20 batches of 50 each

BATCH_SIZE=${1:-50}
MAX_BATCHES=${2:-30}

LANGS=("en" "fr" "es" "ar" "ru")
SLEEP_MS=("600" "700" "700" "900" "700")

echo "[backfill] Starting backfill loop"
echo "[backfill] Batch size: $BATCH_SIZE, Max batches: $MAX_BATCHES"
echo "[backfill] Languages: ${LANGS[@]}"
echo ""

for ((batch=0; batch<MAX_BATCHES; batch++)); do
  OFFSET=$((batch * BATCH_SIZE))
  echo "[backfill-batch=$batch] offset=$OFFSET limit=$BATCH_SIZE"
  
  for i in "${!LANGS[@]}"; do
    LANG=${LANGS[$i]}
    SLEEP=${SLEEP_MS[$i]}
    
    env PYTHONUNBUFFERED=1 \
        ZHIPU_MODEL=glm-4-flash \
        ZHIPU_TIMEOUT_SECONDS=30 \
        ZHIPU_MAX_RETRIES=3 \
        ZHIPU_RETRY_BASE_SECONDS=1 \
        ZHIPU_MAX_INPUT_CHARS=500 \
        ENABLE_REMOTE_TRANSLATION=1 \
        python3 scripts/backfill_card_fields.py \
        --limit $BATCH_SIZE --offset $OFFSET --langs "$LANG" --sleep-ms $SLEEP 2>&1 | grep -E "(checked_rows|updated_rows|skip invalid)"
    
    if [ $? -ne 0 ]; then
      echo "[backfill-error] Language $LANG failed at batch=$batch offset=$OFFSET"
      exit 1
    fi
  done
  
  echo ""
done

echo "[backfill-complete] Processed $((MAX_BATCHES * BATCH_SIZE)) records"

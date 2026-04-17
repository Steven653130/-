import hashlib


def generate_embedding(text):
    # Compatibility fallback: generate a deterministic pseudo-embedding.
    # This keeps the pipeline running on older zhipuai versions that
    # do not provide the modern embeddings client API.
    digest = hashlib.sha256(text.encode("utf-8")).digest()
    return [b / 255.0 for b in digest]
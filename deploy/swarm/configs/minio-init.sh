#!/bin/sh
# One-shot (serviço `minio-init` da stack): cria o bucket e dois usuários de privilégio mínimo
# (SPEC.md §9.4/§11). Idempotente — pode rodar a cada deploy.
#
#   apy-control-plane : leitura/escrita no bucket (sobe certificados e a chave da conta ACME)
#   apy-agent         : só leitura em certificates/* (nunca a chave da conta ACME em acme/*)
#
# Limitação conhecida: o SPEC pede policy por réplica escopada aos domínios que ela serve. Como os
# objetos são certificates/<uuid>, isso exigiria uma policy por certificado — fica para quando
# existir enrollment de credenciais por réplica (SPEC.md §16).
set -eu

root_user="$(cat /run/secrets/minio_root_user)"
root_password="$(cat /run/secrets/minio_root_password)"
app_secret="$(cat /run/secrets/minio_app_secret_key)"
agent_secret="$(cat /run/secrets/minio_agent_secret_key)"
bucket="${MINIO_BUCKET:?}"

until mc alias set core http://minio:9000 "$root_user" "$root_password" >/dev/null 2>&1; do
  echo "aguardando o MinIO..."
  sleep 3
done

mc mb --ignore-existing "core/$bucket"

cat > /tmp/apy-control-plane.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {"Effect": "Allow", "Action": ["s3:ListBucket", "s3:GetBucketLocation"], "Resource": ["arn:aws:s3:::$bucket"]},
    {"Effect": "Allow", "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"], "Resource": ["arn:aws:s3:::$bucket/*"]}
  ]
}
EOF

cat > /tmp/apy-agent.json <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {"Effect": "Allow", "Action": ["s3:GetObject"], "Resource": ["arn:aws:s3:::$bucket/certificates/*"]}
  ]
}
EOF

# `policy create` sobrescreve uma policy de mesmo nome — rodar de novo aplica mudanças.
for name in apy-control-plane apy-agent; do
  mc admin policy create core "$name" "/tmp/$name.json"
done

mc admin user add core apy-control-plane "$app_secret"
mc admin user add core apy-agent "$agent_secret"
# Em re-execuções o attach falha com "already attached" — esperado; qualquer outro erro aparece no log.
for name in apy-control-plane apy-agent; do
  mc admin policy attach core "$name" --user "$name" || echo "policy $name: attach não aplicado (normal se já estava anexada)"
done

echo "MinIO pronto: bucket $bucket, usuários apy-control-plane (rw) e apy-agent (ro certificates/*)."

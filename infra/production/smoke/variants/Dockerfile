# Biến thể image backend cho kiểm rollback/migrate lỗi/VV-IRREVERSIBLE (T35-1 (f)): chỉ thêm MỘT migration giả vào image đã build,
# không đụng mã nguồn trong repo. Không dùng ngoài smoke.
ARG BASE
FROM ${BASE}
ARG MIGRATION
USER root
COPY ${MIGRATION} /var/www/backend/database/migrations/${MIGRATION}
USER 10001:10001

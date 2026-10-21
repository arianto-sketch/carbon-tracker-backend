# Service "offsite" (compose.yml): salinan backup terenkripsi ke object storage S3-compatible.
# rclone untuk upload/unduh, age untuk enkripsi dengan kunci publik, bash + GNU coreutils/gzip
# untuk skrip docker/offsite/offsite.sh (di-mount read-only, bukan disalin ke image).
FROM rclone/rclone:1.69

RUN apk add --no-cache age bash coreutils gzip

ENTRYPOINT ["bash", "/opt/offsite/offsite.sh"]
CMD ["schedule"]

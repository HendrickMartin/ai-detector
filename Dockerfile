FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive HF_HOME=/opt/hf

RUN apt-get update && apt-get install -y \
    apache2 mysql-server php libapache2-mod-php php-mysql \
    python3 python3-pip && rm -rf /var/lib/apt/lists/*

RUN pip3 install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu \
 && pip3 install --no-cache-dir transformers pillow

RUN python3 -c "from transformers import pipeline; pipeline('image-classification', model='umm-maybe/AI-image-detector')" \
 && chmod -R a+rX /opt/hf

RUN printf "upload_max_filesize=5M\npost_max_size=6M\n" > /etc/php/8.1/apache2/conf.d/99-upload.ini

COPY detect.py /var/www/detect.py
COPY init.sql /docker-init/init.sql
COPY start.sh /start.sh
COPY app/ /var/www/html/
RUN rm -f /var/www/html/index.html \
 && mkdir -p /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html/uploads \
 && chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]

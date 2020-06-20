FROM php:7.4-cli

RUN apt-get update -qq && apt-get install -y git libzmq3-dev rsync -qq >/dev/null

# Get the latest version of php-zmq
RUN git clone git://github.com/mkoppanen/php-zmq.git --depth=1 >/dev/null && cd php-zmq \
    && phpize >/dev/null && ./configure >/dev/null \
    && make >/dev/null && make install >/dev/null \
    && cd .. && rm -rf php-zmq

RUN docker-php-ext-enable zmq

COPY . /usr/src/fileserver
WORKDIR /usr/src/fileserver

CMD [ "php", "bin/server" ]

FROM golang:1.26-alpine AS build
WORKDIR /src
COPY . .
RUN go build -o /synaplan-egress ./cmd/synaplan-egress

FROM alpine:3.22
COPY --from=build /synaplan-egress /usr/local/bin/synaplan-egress
EXPOSE 3128
USER nobody
ENTRYPOINT ["synaplan-egress"]

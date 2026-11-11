FROM golang:1.27-alpine@sha256:fa3d596898d9f126af7c01d14ee7597bab3f39cccf392ef36b69fe77cd681f18 AS build
WORKDIR /src
COPY . .
RUN go build -o /synaplan-egress ./cmd/synaplan-egress

FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf
COPY --from=build /synaplan-egress /usr/local/bin/synaplan-egress
EXPOSE 3128
USER nobody
ENTRYPOINT ["synaplan-egress"]

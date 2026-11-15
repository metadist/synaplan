FROM golang:1.27-alpine@sha256:eb61c3873c97416fff0116553fff65d67a3549f72e11d9758849a689cdd8b523 AS build
WORKDIR /src
COPY . .
RUN go build -o /synaplan-egress ./cmd/synaplan-egress

FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf
COPY --from=build /synaplan-egress /usr/local/bin/synaplan-egress
EXPOSE 3128
USER nobody
ENTRYPOINT ["synaplan-egress"]

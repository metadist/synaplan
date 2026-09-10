FROM golang:1.22-bookworm AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -o /synaplan-compute ./cmd/synaplan-compute

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /synaplan-compute /synaplan-compute
USER nonroot:nonroot
ENTRYPOINT ["/synaplan-compute"]

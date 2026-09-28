#!/bin/sh
set -eu

mode=${1:-apply}
case "$mode" in
    apply|--check) ;;
    *) printf '%s\n' 'Unsupported RabbitMQ policy operation.' >&2; exit 2 ;;
esac
if [ "$#" -gt 1 ]; then
    printf '%s\n' 'Unexpected RabbitMQ policy arguments.' >&2
    exit 2
fi

vhost=${RABBITMQ_DEFAULT_VHOST:?Broker vhost is required}
vhost_base64=$(printf '%s' "$vhost" | base64 | tr -d '\n')
definition=$(cat /etc/ideakit-rabbitmq/critical-policy.json)

# Validate before set_policy: it otherwise silently replaces an existing definition.
compatible=$(rabbitmqctl -q eval "
    VHost = base64:decode(<<\"$vhost_base64\">>),
    {ok, Json} = file:read_file(\"/etc/ideakit-rabbitmq/critical-policy.json\"),
    Expected = rabbit_json:decode(Json),
    Matches = fun(P) ->
        ApplyTo = proplists:get_value('apply-to', P),
        Pattern = proplists:get_value(pattern, P),
        case ApplyTo of
            <<\"exchanges\">> -> false;
            <<\"classic_queues\">> -> false;
            <<\"streams\">> -> false;
            _ -> re:run(<<\"critical\">>, Pattern, [{capture, none}]) =/= nomatch
        end
    end,
    Compatible = lists:all(fun(P) ->
        case proplists:get_value(name, P) of
            <<\"ideakit-critical-dlx\">> ->
                proplists:get_value(pattern, P) =:= <<\"^critical$\">> andalso
                proplists:get_value('apply-to', P) =:= <<\"quorum_queues\">> andalso
                proplists:get_value(priority, P) =:= 10 andalso
                maps:from_list(proplists:get_value(definition, P)) =:= Expected;
            _ -> not Matches(P) orelse proplists:get_value(priority, P) < 10
        end
    end, rabbit_policy:list(VHost)),
    Compatible andalso not lists:any(Matches, rabbit_policy:list_op(VHost)).
")
if [ "$compatible" != true ]; then
    printf '%s\n' 'RabbitMQ policy conflict; existing configuration was not changed.' >&2
    exit 1
fi

if [ "$mode" = --check ]; then
    exchanges=$(rabbitmqctl -q list_exchanges --vhost "$vhost" name type durable auto_delete internal arguments --formatter=json)
    queues=$(rabbitmqctl -q list_queues --vhost "$vhost" name type durable auto_delete exclusive arguments policy effective_policy_definition --formatter=json)
    bindings=$(rabbitmqctl -q list_bindings --vhost "$vhost" source_name destination_name destination_kind routing_key arguments --formatter=json)
    exchanges_base64=$(printf '%s' "$exchanges" | base64 | tr -d '\n')
    queues_base64=$(printf '%s' "$queues" | base64 | tr -d '\n')
    bindings_base64=$(printf '%s' "$bindings" | base64 | tr -d '\n')
    ready=$(rabbitmqctl -q eval "
        Exchanges = rabbit_json:decode(base64:decode(<<\"$exchanges_base64\">>)),
        Queues = rabbit_json:decode(base64:decode(<<\"$queues_base64\">>)),
        Bindings = rabbit_json:decode(base64:decode(<<\"$bindings_base64\">>)),
        {ok, Json} = file:read_file(\"/etc/ideakit-rabbitmq/critical-policy.json\"),
        Expected = rabbit_json:decode(Json),
        Has = fun(Rows, Fields) ->
            lists:any(fun(Row) ->
                lists:all(fun({Key, Value}) -> maps:get(Key, Row, undefined) =:= Value end, Fields)
            end, Rows)
        end,
        ExchangesReady = lists:all(fun(Name) ->
            Has(Exchanges, [{<<\"name\">>, Name}, {<<\"type\">>, <<\"direct\">>},
                {<<\"durable\">>, true}, {<<\"auto_delete\">>, false},
                {<<\"internal\">>, false}, {<<\"arguments\">>, []}])
        end, [<<\"ideakit.commands\">>, <<\"ideakit.dead-letter\">>]),
        QueuesReady = lists:all(fun(Name) ->
            Has(Queues, [{<<\"name\">>, Name}, {<<\"type\">>, <<\"quorum\">>},
                {<<\"durable\">>, true}, {<<\"auto_delete\">>, false},
                {<<\"exclusive\">>, <<>>},
                {<<\"arguments\">>, [[<<\"x-queue-type\">>, <<\"longstr\">>, <<\"quorum\">>]]}])
        end, [<<\"critical\">>, <<\"critical.failed\">>]),
        BindingsReady = lists:all(fun({Source, Destination, Key}) ->
            Has(Bindings, [{<<\"source_name\">>, Source}, {<<\"destination_name\">>, Destination},
                {<<\"destination_kind\">>, <<\"queue\">>}, {<<\"routing_key\">>, Key},
                {<<\"arguments\">>, []}])
        end, [{<<\"ideakit.commands\">>, <<\"critical\">>, <<\"critical\">>},
              {<<\"ideakit.dead-letter\">>, <<\"critical.failed\">>, <<\"critical.failed\">>}]),
        PolicyReady = Has(Queues, [{<<\"name\">>, <<\"critical\">>},
            {<<\"policy\">>, <<\"ideakit-critical-dlx\">>}, {<<\"effective_policy_definition\">>, Expected}]),
        NoReturnRoute = Has(Queues, [{<<\"name\">>, <<\"critical.failed\">>},
            {<<\"effective_policy_definition\">>, #{}}]),
        ExchangesReady andalso QueuesReady andalso BindingsReady andalso PolicyReady andalso NoReturnRoute.
    ")
    if [ "$ready" != true ]; then
        printf '%s\n' 'RabbitMQ topology or effective policy mismatch.' >&2
        exit 1
    fi
    printf '%s\n' 'RabbitMQ topology and effective policy verified.'
    exit 0
fi

rabbitmqctl -q set_policy --vhost "$vhost" --apply-to quorum_queues --priority 10 \
    ideakit-critical-dlx '^critical$' "$definition"
printf '%s\n' 'RabbitMQ critical dead-letter policy configured.'

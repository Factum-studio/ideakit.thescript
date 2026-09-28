#!/bin/sh
set -eu

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

rabbitmqctl -q set_policy --vhost "$vhost" --apply-to quorum_queues --priority 10 \
    ideakit-critical-dlx '^critical$' "$definition"
printf '%s\n' 'RabbitMQ critical dead-letter policy configured.'

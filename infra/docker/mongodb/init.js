// Credentials come from the container environment, never command interpolation.
try {
  rs.status();
} catch (error) {
  if (error.code !== 94) throw error; // NotYetInitialized only
  rs.initiate({_id: 'rs0', members: [{_id: 0, host: 'mongo:27017'}]});
}
let primary = false;
for (let attempt = 0; attempt < 60; attempt++) {
  if (db.hello().isWritablePrimary) { primary = true; break; }
  sleep(1000);
}
if (!primary) throw new Error('MongoDB primary election timed out');
const databaseName = process.env.MONGODB_DATABASE || 'message_db';
const database = db.getSiblingDB(databaseName);
function installRole(role, privileges) {
  const definition = {privileges, roles: []};
  if (database.getRole(role)) database.updateRole(role, definition);
  else database.createRole({role, ...definition});
}
function resource(collection, actions) {
  return {resource: {db: databaseName, collection}, actions};
}
installRole('message_runtime_role', [
  resource('messages', ['find', 'insert']),
  resource('conversation_sequences', ['find', 'insert', 'update']),
  resource('outbox_events', ['insert']),
]);
installRole('message_outbox_relay_role', [resource('outbox_events', ['find', 'update'])]);
for (const [user, password, roles] of [
  ['message_runtime', process.env.MONGODB_RUNTIME_PASSWORD, ['message_runtime_role']],
  ['message_outbox_relay', process.env.MONGODB_RELAY_PASSWORD, ['message_outbox_relay_role']],
  ['message_migration', process.env.MONGODB_MIGRATION_PASSWORD, ['dbAdmin', 'readWrite']],
]) {
  if (!password) throw new Error('Missing password for ' + user);
  if (database.getUser(user)) database.updateUser(user, {pwd: password, roles});
  else database.createUser({user, pwd: password, roles});
}
print('MongoDB replica set and service accounts are ready');

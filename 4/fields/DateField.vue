<template>
  <b-form-group
    :id="field.key"
    label-cols-sm="4"
    label-cols-lg="3"
    :description="field.field_description"
    :label="field.label"
    :label-for="field.field_key"
    >
    <b-form-input :id="field.field_key" type="date" v-model="date_data" @change="$emit('update:date_data', date_data);"></b-form-input>
  </b-form-group>
</template>
<script>
import moment from 'moment'
var qs = require('qs');
export default {
  name: 'DateField',
  props: [
    'field','data_select','date_data'
  ],
  mounted () {
    // this.setDateFormat();
  },
  data: () => {
    return {
      selected_record: {},
      key: ''
    }
  },
  watch: {
    data_select(newVal) {
        this.selected_record = newVal
    },
  },
  methods: {
    // setDateFormat: function() {
    //   return moment(this.selected_record[this.key_assign], 'YYYY-MM-DD HH:mm:ss').format('YYYY-MM-DD');
    // }
  },
  computed: {
    data_assign: {
      get() {
        return this.data_select
      },
      set (value) {
        this.selected_record = value
      }
    },
    key_assign: {
      get() {
        return this.field.field_key
      },
      set (value) {
        this.key = value
      }
    }
  }
}
</script>

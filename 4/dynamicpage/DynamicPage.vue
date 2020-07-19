<template>
  <div class="animated fadeIn">
    <b-row>
      <DataListComponent @handleSelect="handleSelect" :list_length="list_length" :name_plural="name_plural" :data_select="data_select" :list_data="list_data"></DataListComponent>
      <ManageDataComponent @getList="getList" :data_select="data_select" :name_singular="name_singular" :name_plural="name_plural" :form_fields="form_fields" :api_url="api_url"></ManageDataComponent>
    </b-row>
  </div>
</template>
<script>
import moment from 'moment'
import DataListComponent from '../DataListComponent.vue'
import ManageDataComponent from '../ManageDataComponent.vue'
var qs = require('qs');
export default {
  name: 'DynamicPage',
  components: {
    DataListComponent,ManageDataComponent
  },
  props:[
    'name_singular','name_plural','list_data','form_fields','api_url'
  ],
  data: () => {
    return {
      data_select: [],
  	}
  },
  filters: {
	},
	computed: {
		list_length: function() {
			return this.list_data.length
		},
	},
  methods: {
		handleSelect(value) {
      this.data_select = value
		},
    getList() {
      this.$emit('getList')
    }
  },
}
</script>
